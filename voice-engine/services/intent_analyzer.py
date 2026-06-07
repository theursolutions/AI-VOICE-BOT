import os
import requests
from dotenv import load_dotenv

load_dotenv()

class IntentAnalyzer:
    def __init__(self):
        self.api_key = os.getenv("GEMINI_API_KEY")
        self.api_url = os.getenv("GEMINI_API_URL")

        if not self.api_key or not self.api_url:
            raise RuntimeError("Gemini API key or URL missing from .env file.")

    def analyze(self, user_input: str) -> str:
        """
        Sends the user input to Gemini API with a strong prompt
        to determine the intent category.
        """

        system_prompt = """
        You are an intent classification engine.
        You must read the user's input and return ONLY one of these categories:
        - conversation   (for general chat, small talk, or open-ended dialogue)
        - data           (for factual questions, database queries, structured info requests)

        Rules:
        1. Do not explain your reasoning.
        2. Output must be only one word: "conversation" or "data".
        3. If unsure, choose "conversation".
        """

        payload = {
            "contents": [
                {"parts": [{"text": f"{system_prompt}\n\nUser Input: {user_input}"}]}
            ]
        }

        try:
            response = requests.post(
                f"{self.api_url}?key={self.api_key}",
                json=payload
            )
            response.raise_for_status()
            data = response.json()
            intent = data["candidates"][0]["content"]["parts"][0]["text"].strip().lower()

            if intent not in ["conversation", "data"]:
                intent = "conversation"  # default fallback

            return intent

        except Exception as e:
            raise RuntimeError(f"Gemini intent analysis error: {str(e)}")
