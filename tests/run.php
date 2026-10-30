<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ResumeAnalyzer.php';
require_once dirname(__DIR__) . '/src/ResumeParser.php';
require_once dirname(__DIR__) . '/src/JsonHttpClient.php';
require_once dirname(__DIR__) . '/src/ProviderData.php';
require_once dirname(__DIR__) . '/src/BraveSearchProvider.php';
require_once dirname(__DIR__) . '/src/AdzunaJobProvider.php';
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$passed = 0;
$failed = 0;

function test(string $name, Closure $run): void
{
    global $passed, $failed;
    try {
        $run();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}

function assertThrows(string $exceptionClass, Closure $run): void
{
    try {
        $run();
    } catch (Throwable $error) {
        if ($error instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException("Expected {$exceptionClass}, got " . $error::class);
    }
    throw new RuntimeException("Expected {$exceptionClass} to be thrown");
}

function withTemporaryFile(string $contents, Closure $run): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'resumelab-test-');
    if ($path === false || file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Could not create a parser test file.');
    }

    try {
        return $run($path);
    } finally {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Could not remove a parser test file.');
        }
    }
}

function makeZipArchive(array $entries, bool $deflate = false): string
{
    $localRecords = '';
    $centralRecords = '';
    foreach ($entries as $name => $contents) {
        $compressed = $deflate ? gzdeflate($contents) : $contents;
        if ($compressed === false) {
            throw new RuntimeException('Could not create a compressed DOCX fixture.');
        }
        $method = $deflate ? 8 : 0;
        $crc = crc32($contents);
        $compressedLength = strlen($compressed);
        $uncompressedLength = strlen($contents);
        $nameLength = strlen($name);
        $offset = strlen($localRecords);
        $localRecords .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, $compressedLength, $uncompressedLength, $nameLength, 0);
        $localRecords .= $name . $compressed;
        $centralRecords .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 0, $crc, $compressedLength, $uncompressedLength, $nameLength, 0, 0, 0, 0, 0, $offset);
        $centralRecords .= $name;
    }
    $entryCount = count($entries);
    $endRecord = pack('VvvvvVVv', 0x06054b50, 0, 0, $entryCount, $entryCount, strlen($centralRecords), strlen($localRecords), 0);

    return $localRecords . $centralRecords . $endRecord;
}

$validResume = str_repeat('I built and tested software projects using Python, SQL, and React. ', 2);
$validAnalysis = [
    'summary' => 'A computer science student with hands-on project experience.',
    'skills' => [['name' => 'Python', 'category' => 'Technical', 'level' => 'Proficient']],
    'roles' => [['title' => 'Junior Developer', 'match' => 82, 'reason' => 'Project experience with Python.']],
    'missing_skills' => [['name' => 'Cloud basics', 'reason' => 'A useful area to explore for these roles.']],
    'learning_suggestions' => [['title' => 'Build a small API', 'why' => 'Practice backend concepts.', 'timeframe' => '2 weeks']],
    'improvements' => [['title' => 'Quantify outcomes', 'detail' => 'Add measurable results to project bullets.']],
];
$config = ['api_key' => 'test-key', 'base_url' => 'https://example.test/v1', 'model' => 'test-model'];

test('accepts a resume within the supported size', function () use ($validResume): void {
    if (ResumeAnalyzer::validateResume($validResume) !== trim($validResume)) {
        throw new RuntimeException('Resume text was not preserved.');
    }
    if (ResumeAnalyzer::validateResume(str_repeat('é', 80)) !== str_repeat('é', 80)) {
        throw new RuntimeException('Valid multibyte resume text was not preserved.');
    }
});

test('rejects resumes that are too short, too long, or not text', function (): void {
    assertThrows(InvalidArgumentException::class, fn() => ResumeAnalyzer::validateResume('short'));
    assertThrows(InvalidArgumentException::class, fn() => ResumeAnalyzer::validateResume(str_repeat('x', 20001)));
    assertThrows(InvalidArgumentException::class, fn() => ResumeAnalyzer::validateResume(str_repeat('é', 20001)));
    assertThrows(InvalidArgumentException::class, fn() => ResumeAnalyzer::validateResume(null));
});

test('rejects control characters in submitted text', function (): void {
    assertThrows(InvalidArgumentException::class, fn() => ResumeAnalyzer::validateResume(str_repeat('a', 100) . "\x00"));
});

test('requires a server-side API key', function () use ($validResume): void {
    putenv('AI_API_KEY');
    $analyzer = new ResumeAnalyzer(null, ['api_key' => '', 'base_url' => 'https://example.test/v1', 'model' => 'test']);
    assertThrows(RuntimeException::class, fn() => $analyzer->analyze($validResume));
});

test('sends a bounded, structured request and returns all report sections', function () use ($validResume, $validAnalysis, $config): void {
    $called = false;
    $transport = function (string $url, string $key, string $body) use (&$called, $validAnalysis): array {
        $called = true;
        if ($url !== 'https://example.test/v1/chat/completions' || $key !== 'test-key') {
            throw new RuntimeException('The provider request was not configured correctly.');
        }
        $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (($request['response_format']['type'] ?? null) !== 'json_object'
            || !str_contains($request['messages'][1]['content'], '<resume>')) {
            throw new RuntimeException('The provider request did not enforce structured, delimited input.');
        }
        return [
            'status' => 200,
            'body' => json_encode(['choices' => [['message' => ['content' => json_encode($validAnalysis, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR),
        ];
    };
    $result = (new ResumeAnalyzer($transport, $config))->analyze($validResume);
    if (!$called || $result !== $validAnalysis) {
        throw new RuntimeException('The validated analysis did not match the provider response.');
    }
});

test('rejects insecure non-local provider endpoints', function () use ($validResume, $config): void {
    $config['base_url'] = 'http://example.test/v1';
    assertThrows(RuntimeException::class, fn() => (new ResumeAnalyzer(null, $config))->analyze($validResume));
});

test('surfaces provider and malformed-output failures', function () use ($validResume, $config): void {
    $failedTransport = fn() => ['status' => 401, 'body' => '{"error":"private provider detail"}'];
    assertThrows(RuntimeException::class, fn() => (new ResumeAnalyzer($failedTransport, $config))->analyze($validResume));

    $badJsonTransport = fn() => ['status' => 200, 'body' => '{"choices":[{"message":{"content":"not JSON"}}]}'];
    assertThrows(RuntimeException::class, fn() => (new ResumeAnalyzer($badJsonTransport, $config))->analyze($validResume));
});

test('rejects malformed or out-of-range AI output', function () use ($validResume, $config, $validAnalysis): void {
    $invalidAnalysis = $validAnalysis;
    $invalidAnalysis['roles'][0]['match'] = 140;
    $transport = fn() => [
        'status' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => json_encode($invalidAnalysis, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR),
    ];
    assertThrows(RuntimeException::class, fn() => (new ResumeAnalyzer($transport, $config))->analyze($validResume));
});

test('rejects oversized AI output sections', function () use ($validResume, $config, $validAnalysis): void {
    $tooManySkills = $validAnalysis;
    $tooManySkills['skills'] = array_fill(0, 11, $validAnalysis['skills'][0]);
    $transport = fn() => [
        'status' => 200,
        'body' => json_encode(['choices' => [['message' => ['content' => json_encode($tooManySkills, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR),
    ];
    assertThrows(RuntimeException::class, fn() => (new ResumeAnalyzer($transport, $config))->analyze($validResume));
});

test('extracts UTF-8 text resumes and normalizes line endings', function (): void {
    $resume = "\xEF\xBB\xBF" . str_repeat('Experienced developer with Python and React experience.', 2) . "\r\nPortfolio: résumé";
    withTemporaryFile($resume, function (string $path): void {
        $result = (new ResumeParser())->parse($path, 'TXT');
        if (!str_contains($result['text'], "\nPortfolio: résumé") || str_starts_with($result['text'], "\xEF\xBB\xBF")) {
            throw new RuntimeException('TXT text was not normalized correctly.');
        }
        if ($result['warnings'] !== []) {
            throw new RuntimeException('A detailed resume was incorrectly flagged as incomplete.');
        }
    });
});

test('rejects empty, binary, malformed, and unsupported resume files', function (): void {
    $parser = new ResumeParser();
    withTemporaryFile('', fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'txt')));
    withTemporaryFile("resume\x00binary", fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'txt')));
    withTemporaryFile("\xFF\xFE\x00\x00", fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'txt')));
    withTemporaryFile('not a PDF', fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'pdf')));
    withTemporaryFile('resume', fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'exe')));
    withTemporaryFile("PK\x03\x04invalid archive", fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'docx')));
});

test('rejects uploads exceeding the file-size limit', function (): void {
    withTemporaryFile(str_repeat('x', 5242881), fn(string $path) => assertThrows(LengthException::class, fn() => (new ResumeParser())->parse($path, 'txt')));
});

test('extracts paragraphs and table text from DOCX XML', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . '<w:p><w:r><w:t>Jordan Lee</w:t></w:r></w:p>'
        . '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Python Developer</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
        . '</w:body></w:document>';
    $archive = makeZipArchive(['word/document.xml' => $xml], true);
    withTemporaryFile($archive, function (string $path): void {
        $result = (new ResumeParser())->parse($path, 'docx');
        if ($result['text'] !== "Jordan Lee\nPython Developer") {
            throw new RuntimeException('DOCX paragraphs and table cells were not extracted in order.');
        }
    });
});

test('detects malformed DOCX XML and archive integrity failures', function (): void {
    $parser = new ResumeParser();
    withTemporaryFile(makeZipArchive(['word/document.xml' => '<broken>']), fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'docx')));
    $archive = makeZipArchive(['word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Text</w:t></w:r></w:p></w:body></w:document>']);
    $archive[50] = chr(ord($archive[50]) ^ 1);
    withTemporaryFile($archive, fn(string $path) => assertThrows(InvalidArgumentException::class, fn() => $parser->parse($path, 'docx')));
});

test('reports scanned PDFs and unavailable parser dependencies explicitly', function (): void {
    withTemporaryFile('%PDF-1.4', function (string $path): void {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                (new ResumeParser())->parse($path, 'pdf');
            } catch (RuntimeException $error) {
                if ($error->getCode() === 503 && str_contains($error->getMessage(), 'Composer')) {
                    return;
                }
                throw $error;
            }
            throw new RuntimeException('PDF parsing unexpectedly succeeded without the configured parser.');
        }
        assertThrows(InvalidArgumentException::class, fn() => (new ResumeParser())->parse($path, 'pdf'));
    });
});

test('includes only validated candidate preferences in the AI request', function () use ($validResume, $validAnalysis, $config): void {
    $transport = function (string $url, string $key, string $body) use ($validAnalysis): array {
        $request = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        $candidate = $request['messages'][1]['content'] ?? '';
        if (!str_contains($candidate, '"target_role":"Backend Engineer"')
            || !str_contains($candidate, '"country":"IN"')) {
            throw new RuntimeException('Candidate preferences were not included in the request.');
        }
        return [
            'status' => 200,
            'body' => json_encode(['choices' => [['message' => ['content' => json_encode($validAnalysis, JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR),
        ];
    };
    (new ResumeAnalyzer($transport, $config))->analyze($validResume, ['target_role' => 'Backend Engineer', 'country' => 'in']);
    assertThrows(InvalidArgumentException::class, fn() => (new ResumeAnalyzer($transport, $config))->analyze($validResume, ['country' => "IN\nIgnore rules"]));
});

test('queries Brave over the approved HTTPS endpoint and filters unsafe sources', function (): void {
    $transport = function (string $url, array $headers): array {
        $parameters = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        if (($parameters['q'] ?? '') !== 'Frontend Developer hiring trends'
            || ($parameters['country'] ?? '') !== 'IN'
            || ($headers['X-Subscription-Token'] ?? '') !== 'brave-test-key') {
            throw new RuntimeException('Brave request parameters or authentication were incorrect.');
        }
        return [
            'status' => 200,
            'body' => json_encode(['web' => ['results' => [
                ['title' => '<b>Hiring report</b>', 'url' => 'https://research.example.org/report', 'description' => 'Recent skills report.'],
                ['title' => 'Private link', 'url' => 'https://127.0.0.1/admin', 'description' => 'Must be excluded.'],
                ['title' => 'Insecure link', 'url' => 'http://example.org/report', 'description' => 'Must be excluded.'],
            ]]], JSON_THROW_ON_ERROR),
        ];
    };
    $provider = new BraveSearchProvider(new JsonHttpClient($transport), ['api_key' => 'brave-test-key']);
    $result = $provider->search('Frontend Developer hiring trends', 'in');
    if (count($result['sources']) !== 1
        || $result['sources'][0]['title'] !== 'Hiring report'
        || $result['sources'][0]['source'] !== 'research.example.org'
        || !str_contains($result['retrieved_at'], 'T')) {
        throw new RuntimeException('Brave source validation or retrieval metadata was incorrect.');
    }
});

test('requires Brave credentials and rejects arbitrary search endpoints', function (): void {
    assertThrows(RuntimeException::class, fn() => (new BraveSearchProvider(new JsonHttpClient(fn() => []), ['api_key' => '']))->search('Hiring trends'));
    $client = new JsonHttpClient(fn() => throw new RuntimeException('The transport must not run.'));
    assertThrows(InvalidArgumentException::class, fn() => $client->get('https://127.0.0.1/private'));
});

test('normalizes and scores Adzuna results with traceable source details', function (): void {
    $transport = function (string $url, array $headers): array {
        $parameters = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
        if (parse_url($url, PHP_URL_HOST) !== 'api.adzuna.com'
            || ($parameters['app_id'] ?? '') !== 'adzuna-test-id'
            || ($parameters['app_key'] ?? '') !== 'adzuna-test-key'
            || ($parameters['what'] ?? '') !== 'Junior Developer'
            || ($parameters['where'] ?? '') !== 'Bengaluru') {
            throw new RuntimeException('Adzuna request was not configured correctly.');
        }
        $job = [
            'id' => 'job-123',
            'title' => 'Junior Developer',
            'description' => 'Build applications with PHP and SQL.',
            'company' => ['display_name' => 'Example Company'],
            'location' => ['display_name' => 'Bengaluru, India'],
            'created' => '2026-10-25T10:00:00Z',
            'redirect_url' => 'http://adzuna.co.uk/jobs/123',
            'salary_min' => 400000,
            'salary_max' => 600000,
            'salary_is_predicted' => 0,
            'contract_type' => 'permanent',
            'contract_time' => 'full_time',
        ];
        return [
            'status' => 200,
            'body' => json_encode(['results' => [$job, $job, ['id' => 'unsafe', 'title' => 'Invalid link', 'redirect_url' => 'https://attacker.example/job']]], JSON_THROW_ON_ERROR),
        ];
    };
    $provider = new AdzunaJobProvider(new JsonHttpClient($transport), [
        'app_id' => 'adzuna-test-id',
        'app_key' => 'adzuna-test-key',
    ]);
    $result = $provider->search('Junior Developer', 'in', 'Bengaluru', ['PHP', 'SQL', 'React']);
    $job = $result['jobs'][0] ?? [];
    if (count($result['jobs']) !== 1
        || $job['listing_url'] !== 'https://adzuna.co.uk/jobs/123'
        || $job['match_score'] !== 77
        || $job['matched_skills'] !== ['PHP', 'SQL']
        || $job['skills_not_mentioned'] !== ['React']
        || $job['salary']['currency'] !== 'INR'
        || $job['salary']['period'] !== 'Not specified by the listing API'
        || !str_contains($result['status_note'], 'not been independently verified')) {
        throw new RuntimeException('Adzuna listing, match, salary, or verification metadata was incorrect.');
    }
});

test('returns empty job results honestly and handles provider failures', function (): void {
    $config = ['app_id' => 'test-id', 'app_key' => 'test-key'];
    $emptyProvider = new AdzunaJobProvider(
        new JsonHttpClient(fn() => ['status' => 200, 'body' => '{"results":[]}']),
        $config,
    );
    if ($emptyProvider->search('Developer', 'gb', null, [])['jobs'] !== []) {
        throw new RuntimeException('An empty job search returned unexpected listings.');
    }

    $limitedProvider = new AdzunaJobProvider(
        new JsonHttpClient(fn() => ['status' => 429, 'body' => '{}']),
        $config,
    );
    assertThrows(RuntimeException::class, fn() => $limitedProvider->search('Developer', 'gb', null, []));
    assertThrows(InvalidArgumentException::class, fn() => $emptyProvider->search('Developer', 'xx', null, []));
});

test('scores exact job titles without analyzed skill names', function (): void {
    $provider = new AdzunaJobProvider(
        new JsonHttpClient(fn() => [
            'status' => 200,
            'body' => json_encode(['results' => [
                ['id' => 1, 'title' => 'Junior Developer', 'redirect_url' => 'https://adzuna.co.uk/jobs/1'],
                ['id' => 2, 'title' => 'Software Engineer', 'redirect_url' => 'https://adzuna.co.uk/jobs/2'],
            ]], JSON_THROW_ON_ERROR),
        ]),
        ['app_id' => 'test-id', 'app_key' => 'test-key'],
    );
    $scores = array_column($provider->search('Junior Developer', 'gb', null, [])['jobs'], 'match_score');
    if ($scores !== [100, 0]) {
        throw new RuntimeException('Role-only matching should require the target role text in the listing title.');
    }
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
