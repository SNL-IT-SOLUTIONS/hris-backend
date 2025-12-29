<!DOCTYPE html>
<html>

<body>
    <h3>Face Recognition Attendance</h3>

    <video id="video" width="320" height="240" autoplay></video>
    <button id="clockIn">Clock In</button>
    <button id="clockOut">Clock Out</button>

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

        function captureAndSend(url) {
            // Capture frame
            context.drawImage(video, 0, 0, 320, 240);

            canvas.toBlob(blob => {
                const formData = new FormData();
                formData.append('face_image', blob, 'face.jpg'); // match Laravel controller

                fetch(url, {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer AV3wQUaFuRJ6Rj4o3RyXbvH9wIGiVcGxjixwbAfZ',
                        },
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => alert(JSON.stringify(data))) // or render nicely in UI
                    .catch(err => console.error(err));
            }, 'image/jpeg');
        }

        document.getElementById('clockIn').addEventListener('click', () => {
            captureAndSend('https://api-hris.slarenasitsolutions.com/public/api/attendance/clock-in');
        });

        document.getElementById('clockOut').addEventListener('click', () => {
            captureAndSend('https://api-hris.slarenasitsolutions.com/public/api/attendance/clock-out');
        });
    </script>
</body>

</html>
