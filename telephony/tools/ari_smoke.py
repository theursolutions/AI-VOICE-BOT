"""Phase 0 ARI smoke test.

Dial 1122 from a lab phone: Asterisk hands the call to the Stasis app
"ai-crm", this script answers it, plays "hello world" and hangs up. It proves
our code can control Asterisk calls, which Phase 2 builds the AI on.

    voice-engine/.venv/Scripts/python telephony/tools/ari_smoke.py --password <ARI_PASSWORD>

Leave it running, dial 1122, Ctrl+C to stop. The password is printed by
telephony/asterisk/install.sh (and kept in /etc/asterisk/lab-credentials.env).
"""

from __future__ import annotations

import argparse
import asyncio
import json
import uuid
from urllib.parse import quote

import httpx
import websockets

APP = "ai-crm"


async def handle_call(ari: httpx.AsyncClient, channel: dict, finished: dict[str, asyncio.Event]) -> None:
    cid = channel["id"]
    caller = channel["caller"]["number"] or "unknown"
    print(f"call from {caller} to {channel['dialplan']['exten']} (channel {cid})")

    # Choose the playback id ourselves so the "finished" event can't arrive
    # before we're listening for it.
    playback_id = str(uuid.uuid4())
    done = finished[playback_id] = asyncio.Event()
    try:
        (await ari.post(f"/channels/{cid}/answer")).raise_for_status()
        (await ari.post(f"/channels/{cid}/play/{playback_id}",
                        params={"media": "sound:hello-world"})).raise_for_status()
        await asyncio.wait_for(done.wait(), timeout=15)
        (await ari.delete(f"/channels/{cid}")).raise_for_status()
        print("  answered, played the prompt, hung up - ARI works")
    except httpx.HTTPStatusError as exc:
        # 404 usually means the caller hung up first.
        print(f"  ARI {exc.response.status_code} on {exc.request.url.path}")
    except asyncio.TimeoutError:
        print("  prompt never finished - are the core sounds installed?")
    finally:
        finished.pop(playback_id, None)


async def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--url", default="http://localhost:8088")
    parser.add_argument("--user", default="ai-crm")
    parser.add_argument("--password", required=True)
    args = parser.parse_args()

    ws_url = (args.url.replace("http", "ws", 1)
              + f"/ari/events?app={APP}&api_key={quote(args.user)}:{quote(args.password)}")

    async with httpx.AsyncClient(base_url=args.url + "/ari",
                                 auth=(args.user, args.password), timeout=10) as ari:
        info = (await ari.get("/asterisk/info")).raise_for_status().json()
        print(f"Asterisk {info['system']['version']}: ARI OK. Dial 1122 now (Ctrl+C to stop).")

        finished: dict[str, asyncio.Event] = {}
        calls: set[asyncio.Task] = set()
        async with websockets.connect(ws_url) as ws:
            async for raw in ws:
                event = json.loads(raw)
                kind = event.get("type")
                if kind == "StasisStart":
                    task = asyncio.create_task(handle_call(ari, event["channel"], finished))
                    calls.add(task)
                    task.add_done_callback(calls.discard)
                elif kind == "PlaybackFinished":
                    waiter = finished.get(event["playback"]["id"])
                    if waiter:
                        waiter.set()


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
