<?php

$defaultUrl = 'https://liljnnprtqwoimmsjuhy.supabase.co/storage/v1/object/public/IPTV-Network/IPTV-NETWORK/19-11-26-Link-1_B.m3u';
$playlistUrl = getenv('M3U_SOURCE_URL') ?: $defaultUrl;
$outputFile = getenv('M3U_OUTPUT_FILE') ?: __DIR__ . '/playlist.m3u';

function fetchM3u(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GitHubActionM3UCategorizer/1.0)',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode >= 400 || trim($response) === '') {
        fwrite(STDERR, "M3U fetch failed ($httpCode): $error\n");
        return null;
    }
    return $response;
}

function detectCategory(string $extinfLine, string $streamUrl): string {
    $parts = explode(',', $extinfLine, 2);
    $channelName = $parts[1] ?? $extinfLine;

    $haystack = strtolower($channelName . ' ' . $extinfLine . ' ' . $streamUrl);

    // ক্রম গুরুত্বপূর্ণ: আগে ম্যাচ করলে সেটিই প্রাধান্য পাবে
    $rules = [
        'Movie' => [
            'movie', 'movies', 'cinema', 'film', 'box office', 'bollywood',
            'hollywood', 'tollywood', 'bhojpuri', 'zee cinema', '&pictures',
            'and pictures', 'sony max', 'star gold', 'movies now', 'mnx',
            'romedy', 'flix', 'zee bangla cinema', 'jalsha movies'
        ],
        'Sports' => [
            'sport', 'sports', 'cric', 'cricket', 'football', 'soccer', 'ten ',
            'star sports', 'sony ten', 'willow', 'espn', 't sports', 'tsports',
            'ptv sports', 'bein', 'sky sports', 'wwe', 'ufc', 'moto', 'f1',
            'badminton', 'tennis', 'khel', 'khela'
        ],
        'Bangladeshi' => [
            'bangladesh', 'bangladeshi', 'bangla', ' bd ', '[bd]', '(bd)', 'bd-', '-bd', '_bd',
            'somoy', 'jamuna', 'channel i', 'channel 24', 'ekattor', 'independent',
            'ntv', 'rtv', 'atn bangla', 'atn news', 'dbc', 'nagorik', 'deepto',
            'maasranga', 'boishakhi', 'gazi', 'gtv', 'desh tv', 'my tv', 'mytv',
            'mohona', 'btv', 'banglavision', 'asian tv', 'bijoy tv',
            'global tv', 'green tv', 'nexus tv', 'madani'
        ],
        'Indian' => [
            'india', 'indian', 'hindi', 'star plus', 'star bharat', 'zee tv',
            'sony tv', 'sony sab', 'colors', 'sun tv', 'vijay', 'asianet',
            'zee bangla', 'star jalsha', 'colors bangla', 'sun bangla',
            'dd national', 'aaj tak', 'ndtv', 'republic', 'abp', 'india tv',
            'malayalam', 'tamil', 'telugu', 'kannada', 'marathi', 'punjabi'
        ],
    ];

    foreach ($rules as $category => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($haystack, strtolower($kw)) !== false) {
                return $category;
            }
        }
    }

    return 'General';
}

function setGroupTitle(string $extinfLine, string $category): string {
    $safeCategory = str_replace('"', '', $category);

    if (preg_match('/group-title="[^"]*"/i', $extinfLine)) {
        return preg_replace('/group-title="[^"]*"/i', 'group-title="' . $safeCategory . '"', $extinfLine, 1);
    }

    if (preg_match('/^(#EXTINF:[^\s,]+)(.*)$/i', $extinfLine, $matches)) {
        return $matches[1] . ' group-title="' . $safeCategory . '"' . $matches[2];
    }

    return $extinfLine;
}

$raw = fetchM3u($playlistUrl);
if ($raw === null) {
    exit(1);
}

$lines = preg_split('/\r\n|\r|\n/', $raw);
$output = ["#EXTM3U"];
$pendingExtinf = null;
$pendingTags = [];
$channelCount = 0;

foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || stripos($trimmed, '#EXTM3U') === 0) {
        continue;
    }

    if (stripos($trimmed, '#EXTINF:') === 0) {
        $pendingExtinf = $trimmed;
        $pendingTags = [];
        continue;
    }

    if ($pendingExtinf !== null && strpos($trimmed, '#') === 0) {
        $pendingTags[] = $trimmed;
        continue;
    }

    if ($pendingExtinf !== null && strpos($trimmed, '#') !== 0) {
        $category = detectCategory($pendingExtinf, $trimmed);
        $updatedExtinf = setGroupTitle($pendingExtinf, $category);

        $output[] = $updatedExtinf;
        foreach ($pendingTags as $tag) {
            $output[] = $tag;
        }
        $output[] = $trimmed;

        $channelCount++;
        $pendingExtinf = null;
        $pendingTags = [];
    }
}

if ($channelCount === 0) {
    fwrite(STDERR, "No valid channels found in source playlist.\n");
    exit(1);
}

$finalContent = implode("\n", $output) . "\n";
if (file_put_contents($outputFile, $finalContent) === false) {
    fwrite(STDERR, "Failed to write output file: $outputFile\n");
    exit(1);
}

echo "Successfully generated {$outputFile} with {$channelCount} channels.\n";
