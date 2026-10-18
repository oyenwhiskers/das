<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Print BIMBSEC</title>
</head>
<body style="margin:0">
    <iframe id="bimbsec-print" src="{{ $url }}" title="BIMBSEC PDF" style="border:0;width:100%;height:100vh"></iframe>
    <script>
        document.getElementById('bimbsec-print').addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
