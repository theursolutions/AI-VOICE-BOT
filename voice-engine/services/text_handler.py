class TextHandler:
    def process(self, text):
        if not text or not text.strip():
            raise ValueError("Empty text provided.")
        return text.strip()
