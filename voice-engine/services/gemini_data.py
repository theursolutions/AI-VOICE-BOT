import os
import requests
from dotenv import load_dotenv

load_dotenv()

class GeminiDataService:
    def __init__(self):
        self.api_key = os.getenv("GEMINI_API_KEY")
        self.api_url = os.getenv("GEMINI_API_URL")

        if not self.api_key or not self.api_url:
            raise RuntimeError("Gemini API key or URL missing from .env file.")

    def fetch_data(self, query: str) -> str:
        """
        Fetch factual or structured information using Gemini API.
        Response should be concise and fact-based.
        """
        system_prompt = """
        You are a precise factual answering system.
        Answer the query accurately, concisely, and without unnecessary elaboration.
        If you don't know, say "I don't know".
        """

        payload = {
            "contents": [
                {"parts": [{"text": f"{system_prompt}\n\nQuery: {query}"}]}
            ]
        }

        try:
            response = requests.post(
                f"{self.api_url}?key={self.api_key}",
                json=payload
            )
            response.raise_for_status()
            data = response.json()
            return data["candidates"][0]["content"]["parts"][0]["text"]

        except Exception as e:
            raise RuntimeError(f"Gemini data API error: {str(e)}")
