<?php

namespace App\Support\Api;

/**
 * Request examples for the API documentation, in eight languages, all from
 * one description of the request so they never disagree. Each uses only the
 * language's standard library: nothing to install.
 */
final class ApiCodeSamples
{
    /** Languages in the order the documentation offers them. */
    public const LANGUAGES = ['curl', 'python', 'javascript', 'php', 'go', 'java', 'csharp', 'ruby'];

    /** The highlighter's grammar for each language. */
    public const GRAMMAR = [
        'curl' => 'bash',
        'python' => 'python',
        'javascript' => 'javascript',
        'php' => 'php',
        'go' => 'go',
        'java' => 'java',
        'csharp' => 'csharp',
        'ruby' => 'ruby',
    ];

    /**
     * @param  array<string, mixed>|null  $body  JSON body, or null for none
     * @param  string|null  $saveAs  file to write a binary (audio) answer to; null prints the JSON answer
     * @return array<string, string> language => code
     */
    public static function all(string $method, string $url, ?array $body = null, ?string $saveAs = null): array
    {
        $method = strtoupper($method);
        $samples = [];
        foreach (self::LANGUAGES as $language) {
            $samples[$language] = self::{$language}($method, $url, $body, $saveAs);
        }

        return $samples;
    }

    // ── Languages ──────────────────────────────────────────────────────

    private static function curl(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $lines = [$method === 'GET' ? 'curl "'.$url.'"' : 'curl -X '.$method.' "'.$url.'"'];
        $lines[] = '  -H "X-Listen-Api-Key: $LISTEN_API_KEY"';
        if ($body !== null) {
            $lines[] = '  -H "Content-Type: application/json"';
            $lines[] = "  -d '".self::indentTail(self::json($body), '  ')."'";
        }
        if ($saveAs !== null) {
            $lines[] = '  --output '.$saveAs;
        }

        return implode(" \\\n", $lines);
    }

    private static function python(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $code = "import json\nimport os\nimport urllib.request\n\n";
        if ($body !== null) {
            $code .= 'payload = '.self::pythonLiteral($body)."\n\n";
        }
        $code .= "request = urllib.request.Request(\n";
        $code .= '    "'.$url."\",\n";
        if ($body !== null) {
            $code .= "    data=json.dumps(payload).encode(\"utf-8\"),\n";
        }
        $code .= "    headers={\n        \"X-Listen-Api-Key\": os.environ[\"LISTEN_API_KEY\"],\n";
        if ($body !== null) {
            $code .= "        \"Content-Type\": \"application/json\",\n";
        }
        $code .= "    },\n";
        if ($method !== 'GET') {
            $code .= '    method="'.$method."\",\n";
        }
        $code .= ")\n\n";
        $code .= "# Errors raise urllib.error.HTTPError; its body holds the JSON error.\n";
        $code .= "with urllib.request.urlopen(request, timeout=300) as response:\n";
        if ($saveAs !== null) {
            $code .= '    with open("'.$saveAs."\", \"wb\") as file:\n        file.write(response.read())";
        } else {
            $code .= '    print(json.dumps(json.load(response), indent=2, ensure_ascii=False))';
        }

        return $code;
    }

    private static function javascript(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $code = "// Node.js 18 or later (fetch is built in).\n";
        if ($saveAs !== null) {
            $code .= "import { writeFile } from \"node:fs/promises\";\n\n";
        }
        $code .= 'const response = await fetch("'.$url."\", {\n";
        if ($method !== 'GET') {
            $code .= '  method: "'.$method."\",\n";
        }
        $code .= "  headers: {\n    \"X-Listen-Api-Key\": process.env.LISTEN_API_KEY,\n";
        if ($body !== null) {
            $code .= "    \"Content-Type\": \"application/json\",\n";
        }
        $code .= "  },\n";
        if ($body !== null) {
            $code .= '  body: JSON.stringify('.self::indentTail(self::json($body), '  ')."),\n";
        }
        $code .= "});\n\n";
        $code .= "if (!response.ok) {\n  const { error } = await response.json();\n  throw new Error(`\${error.code}: \${error.message} (\${error.request_id})`);\n}\n\n";
        $code .= $saveAs !== null
            ? 'await writeFile("'.$saveAs.'", Buffer.from(await response.arrayBuffer()));'
            : 'console.log(await response.json());';

        return $code;
    }

    private static function php(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $code = "<?php\n\n";
        if ($body !== null) {
            $code .= "\$payload = <<<'JSON'\n".self::json($body, '    ')."\nJSON;\n\n";
        }
        $code .= "\$curl = curl_init('".$url."');\n";
        $code .= "curl_setopt_array(\$curl, [\n";
        if ($method !== 'GET') {
            $code .= "    CURLOPT_CUSTOMREQUEST => '".$method."',\n";
        }
        $code .= "    CURLOPT_HTTPHEADER => [\n        'X-Listen-Api-Key: ' . getenv('LISTEN_API_KEY'),\n";
        if ($body !== null) {
            $code .= "        'Content-Type: application/json',\n";
        }
        $code .= "    ],\n";
        if ($body !== null) {
            $code .= "    CURLOPT_POSTFIELDS => \$payload,\n";
        }
        $code .= "    CURLOPT_RETURNTRANSFER => true,\n    CURLOPT_TIMEOUT => 300,\n]);\n";
        $code .= "\$body = curl_exec(\$curl);\n\$status = curl_getinfo(\$curl, CURLINFO_RESPONSE_CODE);\ncurl_close(\$curl);\n\n";
        $code .= "if (\$status >= 400) {\n    \$error = json_decode(\$body, true)['error'];\n    throw new RuntimeException(\"{\$error['code']}: {\$error['message']} ({\$error['request_id']})\");\n}\n\n";
        $code .= $saveAs !== null
            ? "file_put_contents('".$saveAs."', \$body);"
            : 'print_r(json_decode($body, true));';

        return $code;
    }

    private static function go(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $imports = ['fmt', 'io', 'net/http', 'os'];
        if ($body !== null) {
            $imports[] = 'strings';
        }
        $code = "package main\n\nimport (\n";
        foreach ($imports as $import) {
            $code .= "\t\"".$import."\"\n";
        }
        $code .= ")\n\nfunc main() {\n";
        if ($body !== null) {
            $code .= "\tbody := strings.NewReader(`".self::json($body)."`)\n";
            $code .= "\treq, err := http.NewRequest(\"".$method.'", "'.$url."\", body)\n";
        } else {
            $code .= "\treq, err := http.NewRequest(\"".$method.'", "'.$url."\", nil)\n";
        }
        $code .= "\tif err != nil {\n\t\tpanic(err)\n\t}\n";
        $code .= "\treq.Header.Set(\"X-Listen-Api-Key\", os.Getenv(\"LISTEN_API_KEY\"))\n";
        if ($body !== null) {
            $code .= "\treq.Header.Set(\"Content-Type\", \"application/json\")\n";
        }
        $code .= "\n\tres, err := http.DefaultClient.Do(req)\n\tif err != nil {\n\t\tpanic(err)\n\t}\n\tdefer res.Body.Close()\n\n";
        $code .= "\tdata, err := io.ReadAll(res.Body)\n\tif err != nil {\n\t\tpanic(err)\n\t}\n";
        $code .= "\tif res.StatusCode >= 400 {\n\t\tpanic(fmt.Sprintf(\"%d: %s\", res.StatusCode, data))\n\t}\n\n";
        $code .= $saveAs !== null
            ? "\tif err := os.WriteFile(\"".$saveAs."\", data, 0o644); err != nil {\n\t\tpanic(err)\n\t}\n}"
            : "\tfmt.Println(string(data))\n}";

        return $code;
    }

    private static function java(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $code = "// Java 17 or later.\nimport java.net.URI;\nimport java.net.http.HttpClient;\nimport java.net.http.HttpRequest;\nimport java.net.http.HttpResponse;\n";
        if ($saveAs !== null) {
            $code .= "import java.nio.file.Files;\nimport java.nio.file.Path;\n";
        }
        $code .= "\npublic class ListenExample {\n    public static void main(String[] args) throws Exception {\n";
        if ($body !== null) {
            $code .= "        String body = \"\"\"\n".self::json($body, '            ')."\n            \"\"\";\n\n";
        }
        $code .= '        HttpRequest request = HttpRequest.newBuilder(URI.create("'.$url."\"))\n";
        $code .= "            .header(\"X-Listen-Api-Key\", System.getenv(\"LISTEN_API_KEY\"))\n";
        if ($body !== null) {
            $code .= "            .header(\"Content-Type\", \"application/json\")\n";
        }
        $code .= match ($method) {
            'POST' => '            .POST(HttpRequest.BodyPublishers.ofString('.($body !== null ? 'body' : '""')."))\n",
            'DELETE' => "            .DELETE()\n",
            default => "            .GET()\n",
        };
        $code .= "            .build();\n\n";
        $code .= "        HttpResponse<byte[]> response = HttpClient.newHttpClient()\n            .send(request, HttpResponse.BodyHandlers.ofByteArray());\n\n";
        $code .= "        if (response.statusCode() >= 400) {\n            throw new RuntimeException(new String(response.body()));\n        }\n\n";
        $code .= $saveAs !== null
            ? '        Files.write(Path.of("'.$saveAs."\"), response.body());\n    }\n}"
            : "        System.out.println(new String(response.body()));\n    }\n}";

        return $code;
    }

    private static function csharp(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $code = "// .NET 7 or later.\n";
        if ($body !== null) {
            $code .= "using System.Text;\n\n";
        }
        $code .= "using var http = new HttpClient { Timeout = TimeSpan.FromMinutes(5) };\n";
        $code .= "http.DefaultRequestHeaders.Add(\"X-Listen-Api-Key\", Environment.GetEnvironmentVariable(\"LISTEN_API_KEY\"));\n\n";
        if ($body !== null) {
            $code .= "var body = new StringContent(\"\"\"\n".self::json($body, '    ')."\n    \"\"\", Encoding.UTF8, \"application/json\");\n";
        }
        $code .= match ($method) {
            'POST' => '$response = await http.PostAsync("'.$url.'", '.($body !== null ? 'body' : 'null').");\n",
            'DELETE' => '$response = await http.DeleteAsync("'.$url."\");\n",
            default => '$response = await http.GetAsync("'.$url."\");\n",
        };
        $code = str_replace('$response =', 'var response =', $code);
        $code .= "\nif (!response.IsSuccessStatusCode)\n{\n    throw new Exception(await response.Content.ReadAsStringAsync());\n}\n\n";
        $code .= $saveAs !== null
            ? 'await File.WriteAllBytesAsync("'.$saveAs.'", await response.Content.ReadAsByteArrayAsync());'
            : 'Console.WriteLine(await response.Content.ReadAsStringAsync());';

        return $code;
    }

    private static function ruby(string $method, string $url, ?array $body, ?string $saveAs): string
    {
        $class = match ($method) {
            'POST' => 'Post',
            'DELETE' => 'Delete',
            default => 'Get',
        };
        $code = "require \"json\"\nrequire \"net/http\"\n\n";
        $code .= 'uri = URI("'.$url."\")\n";
        $code .= 'request = Net::HTTP::'.$class.".new(uri)\n";
        $code .= "request[\"X-Listen-Api-Key\"] = ENV.fetch(\"LISTEN_API_KEY\")\n";
        if ($body !== null) {
            $code .= "request[\"Content-Type\"] = \"application/json\"\n";
            $code .= "request.body = <<~'JSON'\n".self::json($body, '  ')."\nJSON\n";
        }
        $code .= "\nresponse = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == \"https\", read_timeout: 300) do |http|\n  http.request(request)\nend\n\n";
        $code .= "raise \"#{response.code}: #{response.body}\" unless response.is_a?(Net::HTTPSuccess)\n\n";
        $code .= $saveAs !== null
            ? 'File.binwrite("'.$saveAs.'", response.body)'
            : 'puts JSON.pretty_generate(JSON.parse(response.body))';

        return $code;
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Pretty JSON with two-space indentation, every line after the first
     * prefixed with $indent (all lines when $indent is used for a block).
     */
    public static function json(mixed $value, string $indent = ''): string
    {
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $json = (string) preg_replace_callback('/^((?: {4})+)/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[1]), 4)), $json);

        if ($indent === '') {
            return $json;
        }

        return implode("\n", array_map(static fn (string $line): string => $indent.$line, explode("\n", $json)));
    }

    /** Prefixes every line but the first (for text that continues an open line). */
    private static function indentTail(string $text, string $indent): string
    {
        $lines = explode("\n", $text);

        return implode("\n", array_merge([array_shift($lines)], array_map(static fn (string $line): string => $indent.$line, $lines)));
    }

    /** A Python literal for a JSON value (True/False/None, four-space indents). */
    private static function pythonLiteral(mixed $value, int $level = 0): string
    {
        $pad = str_repeat('    ', $level + 1);
        $close = str_repeat('    ', $level);

        if (is_array($value)) {
            if ($value === []) {
                return '{}';
            }
            $isList = array_is_list($value);
            $items = [];
            foreach ($value as $key => $item) {
                $items[] = $pad.($isList ? '' : self::pythonLiteral((string) $key).': ').self::pythonLiteral($item, $level + 1);
            }

            return ($isList ? '[' : '{')."\n".implode(",\n", $items).",\n".$close.($isList ? ']' : '}');
        }

        return match (true) {
            $value === true => 'True',
            $value === false => 'False',
            $value === null => 'None',
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        };
    }
}
