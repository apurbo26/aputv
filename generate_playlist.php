<?php

$defaultUrl = 'https://liljnnprtqwoimmsjuhy.supabase.co/storage/v1/object/public/IPTV-Network/IPTV-NETWORK/19-11-26-Link-1_B.m3u';
$playlistUrl = getenv('M3U_SOURCE_URL') ?: $defaultUrl;
$outputFile = getenv('M3U_OUTPUT_FILE') ?: __DIR__ . '/playlist.m3u';
$categoriesDir = getenv('M3U_CATEGORIES_DIR') ?: __DIR__ . '/categories';

function fetchM3u(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GitHubActionM3UCategorizer/1.2)',
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

/**
 * categories/ ফোল্ডারের সব .txt ফাইল থেকে ক্যাটাগরি ও চ্যানেলের নাম লোড করে
 */
function loadCategoryFolderRules(string $categoriesDir): array {
    $rules = [];
    if (!is_dir($categoriesDir)) {
        return $rules;
    }

    $files = glob(rtrim($categoriesDir, '/\\') . '/*.txt');
    if ($files === false) {
        return $rules;
    }

    foreach ($files as $file) {
        $categoryName = trim(pathinfo($file, PATHINFO_FILENAME));
        if ($categoryName === '') {
            continue;
        }

        $lines = preg_split('/\r\n|\r|\n/', (string)file_get_contents($file));
        $channelNames = [];
        foreach ($lines as $line) {
            $clean = trim($line);
            if ($clean === '' || strpos($clean, '#') === 0) {
                continue;
            }
            $channelNames[] = mb_strtolower($clean);
        }

        if (!empty($channelNames)) {
            $rules[$categoryName] = $channelNames;
        }
    }

    return $rules;
}

function extractExistingGroupTitle(string $extinfLine): ?string {
    if (preg_match('/group-title="([^"]*)"/i', $extinfLine, $matches)) {
        $group = trim($matches[1]);
        if ($group !== '' && strcasecmp($group, 'uncategorized') !== 0 && strcasecmp($group, 'general') !== 0) {
            return $group;
        }
    }
    return null;
}

function extractChannelName(string $extinfLine): string {
    $parts = explode(',', $extinfLine, 2);
    return trim($parts[1] ?? $extinfLine);
}

function detectCategory(string $extinfLine, string $streamUrl, array $folderRules): string {
    // ১. আগে থেকেই ক্যাটাগরি করা থাকলে অটোমেটিক ওই নামেই বসে যাবে
    $existingGroup = extractExistingGroupTitle($extinfLine);
    if ($existingGroup !== null) {
        return $existingGroup;
    }

    $channelName = extractChannelName($extinfLine);
    $nameLower = mb_strtolower($channelName);
    $haystack = mb_strtolower($channelName . ' ' . $extinfLine . ' ' . $streamUrl);

    // ২. categories/ ফোল্ডারের .txt ফাইলে দেওয়া চ্যানেলের নামের সাথে মিলিয়ে দেখবে
    foreach ($folderRules as $category => $channelNames) {
        foreach ($channelNames as $targetName) {
            if ($targetName !== '' && mb_strpos($nameLower, $targetName) !== false) {
                return $category;
            }
        }
    }

    // ৩. ফোল্ডারে না থাকলে ডিফল্ট keyword নিয়মে ক্যাটাগরি ঠিক করবে
    $autoRules = [
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

    foreach ($autoRules as $category => $keywords) {
        foreach ($keywords as $kw) {
            if (mb_strpos($haystack, mb_strtolower($kw)) !== false) {
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

$folderRules = loadCategoryFolderRules($categoriesDir);
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
        $category = detectCategory($pendingExtinf, $trimmed, $folderRules);
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
