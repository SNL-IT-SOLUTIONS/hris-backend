<!DOCTYPE html>
<html>

<body>
    <h3>Face Registration Test</h3>
    <video id="video" width="320" height="240" autoplay></video>
    <button id="snap">Capture & Upload</button>
    <canvas id="canvas" width="320" height="240" style="display:none;"></canvas>

    <script>
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const context = canvas.getContext('2d');

        // Request webcam
        navigator.mediaDevices.getUserMedia({
                video: true
            })
            .then(stream => video.srcObject = stream)
            .catch(err => {
                console.error("Cannot access camera:", err);
                alert("Cannot access camera. Make sure permissions are allowed and you're using localhost/https.");
            });

        document.getElementById('snap').addEventListener('click', () => {
            // Capture frame
            context.drawImage(video, 0, 0, 320, 240);

            canvas.toBlob(blob => {
                const formData = new FormData();
                formData.append('face_image', blob, 'face.jpg'); // matches controller's file validation

                // Localhost URL for testing with php artisan serve
                fetch('https://api-hris.slarenasitsolutions.com/public/api/register-face', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer AV3wQUaFuRJ6Rj4o3RyXbvH9wIGiVcGxjixwbAfZ', // replace with your token
                        },
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => console.log(data))
                    .catch(err => console.error(err));
            }, 'image/jpeg');
        });
    </script>
</body>

</html>
