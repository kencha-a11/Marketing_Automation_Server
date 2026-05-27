<!DOCTYPE html>
<html>

<head>
    <title>Web Terminal</title>
    <style>
        body {
            background: #0a0e1a;
            color: #00ff99;
            font-family: monospace;
            padding: 20px;
        }

        pre {
            background: #111;
            padding: 10px;
            border-left: 4px solid #00ff99;
            overflow-x: auto;
        }

        input,
        button {
            background: #222;
            color: #0f0;
            border: 1px solid #00ff99;
            padding: 8px;
            font-family: monospace;
        }

        input {
            width: 70%;
        }

        button {
            cursor: pointer;
        }

        .output {
            margin-top: 20px;
        }
    </style>
</head>

<body>
    <h2>🔧 Laravel Web Terminal</h2>
    <form method="GET">
        <input type="hidden" name="key" value="{{ request('key') }}">
        <input type="text" name="command" placeholder="e.g., migrate, queue:work --once, logs:tail"
            value="{{ $command ?? '' }}" autofocus>
        <button type="submit">Run</button>
    </form>
    <div class="output">
        <pre>{{ $output ?? 'Enter a command' }}</pre>
    </div>
    <hr>
    <small>Allowed commands: queue:work, queue:restart, migrate, cache:clear, config:clear, route:clear, view:clear,
        logs:tail, help, list, make:*</small>
</body>

</html>
