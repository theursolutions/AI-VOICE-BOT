import os
import requests
from dotenv import load_dotenv

load_dotenv()

class GeminiConversationService:
    def __init__(self):
        self.api_key = os.getenv("GEMINI_API_KEY")
        self.api_url = os.getenv("GEMINI_API_URL")

        if not self.api_key or not self.api_url:
            raise RuntimeError("Gemini API key or URL missing from .env file.")

    def generate(self, prompt: str) -> str:
        """
        Generates conversational responses using Gemini API.
        """
        system_prompt = """
        You are a friendly and knowledgeable AI assistant.
        Provide engaging and helpful responses to the user's input.
        Avoid one-word answers, be polite, and keep the tone natural.
        """

        payload = {
            "contents": [
                {"parts": [{"text": f"{system_prompt}\n\nUser: {prompt}"}]}
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
            raise RuntimeError(f"Gemini conversation API error: {str(e)}")
