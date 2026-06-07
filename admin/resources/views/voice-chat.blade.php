<!DOCTYPE html>
<html>
<head>
    <title>Voice Bot</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <h2>🎤 Record Your Question</h2>

    <button id="startBtn">Start Recording</button>
    <button id="stopBtn" disabled>Stop Recording</button>
    <button id="sendBtn" disabled>Send to Server</button>

    <h3>🔊 Your Recorded Voice:</h3>
    <audio id="userAudio" controls></audio>

    <h3>🤖 Bot Response:</h3>
    <audio id="botAudio" controls></audio>

    <script>
        let mediaRecorder;
        let audioChunks = [];

        $('#startBtn').click(async function () {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            mediaRecorder.start();
            audioChunks = [];

            mediaRecorder.ondataavailable = e => {
                audioChunks.push(e.data);
            };

            mediaRecorder.onstop = () => {
                const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
                const audioUrl = URL.createObjectURL(audioBlob);
                $('#userAudio').attr('src', audioUrl);
                $('#sendBtn').data('blob', audioBlob).prop('disabled', false);
            };

            $('#startBtn').prop('disabled', true);
            $('#stopBtn').prop('disabled', false);
        });

        $('#stopBtn').click(function () {
            mediaRecorder.stop();
            $('#startBtn').prop('disabled', false);
            $('#stopBtn').prop('disabled', true);
        });

        $('#sendBtn').click(function () {
            const blob = $(this).data('blob');
            const formData = new FormData();
            formData.append('voice', blob, 'voice.wav');

            $.ajax({
                url: "{{ route('voice.send') }}",
                method: "POST",
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                data: formData,
                processData: false,
                contentType: false,
                xhrFields: {
                    responseType: 'blob' // We expect audio file back
                },
                success: function(response) {
                    const audioUrl = URL.createObjectURL(response);
                    $('#botAudio').attr('src', audioUrl);
                    alert('Bot responded!');
                },
                error: function(xhr) {
                    alert("Failed to get bot response");
                    console.log(xhr.responseText);
                }
            });
        });
    </script>
</body>
</html>
