<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Agent Voices</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card audio {
            width: 100%;
        }
    </style>
</head>
<body>
<div class="container py-5">
    <h2 class="mb-4 text-center">🗣️ Configure Agent Voices</h2>

    <div class="text-center mb-4">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addVoiceModal">➕ Add New Voice</button>
    </div>

    <div class="row">
        @forelse($voices as $voice)
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm p-3">
                    <h5>{{ $voice['name'] }}</h5>
                    <p><strong>ID:</strong> {{ $voice['voice_id'] }}</p>
                    <audio controls src="{{ asset('storage/' . $voice['file']) }}"></audio>
                    <p class="text-muted small mt-2">Saved: {{ $voice['created_at'] }}</p>
                </div>
            </div>
        @empty
            <p class="text-center">No voices found. Add your first one!</p>
        @endforelse
    </div>
</div>

<!-- Add Voice Modal -->
<div class="modal fade" id="addVoiceModal" tabindex="-1" aria-labelledby="addVoiceModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content shadow">
            <div class="modal-header">
                <h5 class="modal-title">🎙️ Record New Voice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="voiceForm">
                @csrf
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="voice_name" class="form-label">Voice Name</label>
                        <input type="text" name="voice_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <button type="button" id="recordBtn" class="btn btn-outline-primary w-100">🎙️ Start Recording</button>
                        <audio id="preview" class="mt-3 d-none" controls></audio>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success w-100">Upload to ElevenLabs</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>

<script>
let recorder, stream, audioBlob;

$('#recordBtn').on('click', async function () {
    if (!recorder) {
        stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        recorder = new MediaRecorder(stream);
        const chunks = [];

        recorder.ondataavailable = e => chunks.push(e.data);
        recorder.onstop = () => {
            audioBlob = new Blob(chunks, { type: 'audio/webm' });
            const audioURL = URL.createObjectURL(audioBlob);
            $('#preview').attr('src', audioURL).removeClass('d-none');
        };

        recorder.start();
        $(this).text('🛑 Stop Recording').removeClass('btn-outline-primary').addClass('btn-outline-danger');
    } else {
        recorder.stop();
        stream.getTracks().forEach(track => track.stop());
        recorder = null;
        $(this).text('🎙️ Start Recording').removeClass('btn-outline-danger').addClass('btn-outline-primary');
    }
});

$('#voiceForm').on('submit', function (e) {
    e.preventDefault();
    if (!audioBlob) return alert('Please record your voice before uploading.');

    const formData = new FormData(this);
    formData.append('audio_blob', audioBlob, 'voice_sample.webm');

    $.ajax({
        url: '{{ route('agent-voices.store') }}',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function (res) {
            alert('✅ Voice uploaded!');
            location.reload();
        },
        error: function (err) {
            alert('❌ Upload failed: ' + (err.responseJSON?.error || 'Unknown error'));
        }
    });
});
</script>
</body>
</html>
