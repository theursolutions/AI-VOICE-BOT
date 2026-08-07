"""Qdrant wrapper.

One collection named ``crm_chunks`` holds all projects. Project-level
isolation is enforced via a ``project_id`` payload filter on every
search. Each ``source_id`` (a row in Laravel's ``project_sources`` table)
groups the chunks that came from one ingest job — we use it for
re-ingest deletes and for the optional ``source_ids`` filter on query.
"""

from __future__ import annotations

import logging
import uuid
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

from app.config import get_settings
from app.services.embedding_service import EMBEDDING_DIM

logger = logging.getLogger(__name__)


@dataclass
class VectorPoint:
    id: str
    vector: List[float]
    payload: Dict[str, Any] = field(default_factory=dict)


@dataclass
class SearchHit:
    id: str
    score: float
    payload: Dict[str, Any]


class VectorStore:
    """Thin wrapper around ``qdrant_client.QdrantClient``.

    On instantiation, ensures the configured collection exists with the
    correct dimensionality + cosine distance. All operations run in a
    worker thread because the official Qdrant Python client is sync.
    """

    def __init__(
        self,
        url: Optional[str] = None,
        api_key: Optional[str] = None,
        collection: Optional[str] = None,
    ) -> None:
        settings = get_settings()
        self.url = url or settings.qdrant_url
        self.api_key = api_key or settings.qdrant_api_key
        self.collection = collection or settings.qdrant_collection
        self._client = None  # type: ignore[assignment]
        self._ensure_client()
        self._ensure_collection()

    # -- internal ----------------------------------------------------------
    def _ensure_client(self) -> None:
        if self._client is not None:
            return
        try:
            from qdrant_client import QdrantClient

            self._client = QdrantClient(
                url=self.url,
                api_key=self.api_key,
                prefer_grpc=False,
                timeout=30.0,
            )
        except Exception:  # noqa: BLE001
            logger.exception("Failed to initialise Qdrant client at %s", self.url)
            self._client = None

    def _ensure_collection(self) -> None:
        if self._client is None:
            logger.warning(
                "Qdrant client unavailable; skipping collection bootstrap. "
                "Ingest + query will fail until Qdrant is reachable."
            )
            return
        try:
            from qdrant_client.http import models as qmodels  # type: ignore

            existing = {c.name for c in self._client.get_collections().collections}
            if self.collection not in existing:
                self._client.create_collection(
                    collection_name=self.collection,
                    vectors_config=qmodels.VectorParams(
                        size=EMBEDDING_DIM,
                        distance=qmodels.Distance.COSINE,
                    ),
                )
                logger.info("Created Qdrant collection %s", self.collection)
                # Payload indexes for the two filters we use on every search.
                # TODO: also index source_type once we have multiple types in use.
                for field_name in ("project_id", "source_id"):
                    try:
                        self._client.create_payload_index(
                            collection_name=self.collection,
                            field_name=field_name,
                            field_schema=qmodels.PayloadSchemaType.INTEGER,
                        )
                    except Exception:  # noqa: BLE001
                        logger.debug("payload index for %s already exists", field_name)
        except Exception:  # noqa: BLE001
            logger.exception("Failed to ensure Qdrant collection")

    # -- public API --------------------------------------------------------
    def upsert(self, points: List[VectorPoint]) -> None:
        if not points or self._client is None:
            return
        from qdrant_client.http import models as qmodels  # type: ignore

        qpoints = [
            qmodels.PointStruct(id=p.id, vector=p.vector, payload=p.payload)
            for p in points
        ]
        self._client.upsert(collection_name=self.collection, points=qpoints, wait=True)

    def search(
        self,
        project_id: int,
        query_vector: List[float],
        top_k: int = 5,
        source_ids: Optional[List[int]] = None,
    ) -> List[SearchHit]:
        if self._client is None:
            return []
        from qdrant_client.http import models as qmodels  # type: ignore

        must: List[Any] = [
            qmodels.FieldCondition(
                key="project_id",
                match=qmodels.MatchValue(value=int(project_id)),
            )
        ]
        if source_ids:
            must.append(
                qmodels.FieldCondition(
                    key="source_id",
                    match=qmodels.MatchAny(any=[int(s) for s in source_ids]),
                )
            )

        # qdrant-client >=1.10 removed ``search`` in favour of ``query_points``
        # (the older method now raises AttributeError). ``query_points``
        # returns a response object whose ``.points`` holds the ScoredPoints.
        response = self._client.query_points(
            collection_name=self.collection,
            query=query_vector,
            limit=top_k,
            query_filter=qmodels.Filter(must=must),
            with_payload=True,
        )
        return [
            SearchHit(id=str(p.id), score=float(p.score), payload=dict(p.payload or {}))
            for p in response.points
        ]

    def delete_by_source(self, source_id: int) -> None:
        if self._client is None:
            return
        from qdrant_client.http import models as qmodels  # type: ignore

        self._client.delete(
            collection_name=self.collection,
            points_selector=qmodels.FilterSelector(
                filter=qmodels.Filter(
                    must=[
                        qmodels.FieldCondition(
                            key="source_id",
                            match=qmodels.MatchValue(value=int(source_id)),
                        )
                    ]
                )
            ),
            wait=True,
        )

    def delete_by_project_source(self, project_id: int, source_id: int) -> None:
        """Delete vectors for one source within ONE project. Source IDs are
        only unique per-tenant (project 1 and 2 can both have source #6), so
        scoping by project_id too prevents deleting another project's data."""
        if self._client is None:
            return
        from qdrant_client.http import models as qmodels  # type: ignore

        self._client.delete(
            collection_name=self.collection,
            points_selector=qmodels.FilterSelector(
                filter=qmodels.Filter(
                    must=[
                        qmodels.FieldCondition(
                            key="project_id",
                            match=qmodels.MatchValue(value=int(project_id)),
                        ),
                        qmodels.FieldCondition(
                            key="source_id",
                            match=qmodels.MatchValue(value=int(source_id)),
                        ),
                    ]
                )
            ),
            wait=True,
        )

    @staticmethod
    def new_point_id() -> str:
        """Helper for callers — Qdrant accepts UUID-shaped strings."""

        return str(uuid.uuid4())
