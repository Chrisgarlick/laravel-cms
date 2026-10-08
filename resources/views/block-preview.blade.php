{{--
    A block preview with no site stylesheet. An application gets a real one by
    adding resources/views/cms/block-preview.blade.php that loads its CSS.
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <style>body{margin:0;font:16px/1.6 system-ui,sans-serif}</style>
</head>
<body>{!! $body !!}</body>
</html>
