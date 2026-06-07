import os
import uuid
import whisper


class AudioTranscriber:
    def __init__(self, model_name="base", upload_dir="uploads"):
        self.model_name = model_name
        self.upload_dir = os.path.abspath(upload_dir)  # absolute path
        os.makedirs(self.upload_dir, exist_ok=True)
        self.model = whisper.load_model(self.model_name)

    def transcribe(self, file_bytes, filename):
        file_ext = filename.split(".")[-1].lower()
        if file_ext not in ["wav", "mp3", "m4a"]:
            raise ValueError("Unsupported file format. Use wav, mp3, or m4a.")

        file_path = os.path.join(self.upload_dir, f"{uuid.uuid4()}.{file_ext}")

        # Save file
        with open(file_path, "wb") as f:
            f.write(file_bytes)

        # Debug log
        print(f"DEBUG: Saved file to {file_path}, size = {os.path.getsize(file_path)} bytes")

        # Verify file exists and is not empty
        if not os.path.exists(file_path) or os.path.getsize(file_path) == 0:
            raise RuntimeError(f"File not saved correctly: {file_path}")

        # Run whisper
        try:
            result = self.model.transcribe(file_path)
            return result.get("text", "").strip()
        except Exception as e:
            raise RuntimeError(f"Transcription failed: {str(e)}")
