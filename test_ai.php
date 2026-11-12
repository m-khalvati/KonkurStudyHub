<?php
require_once 'config.php';
$key = trim(AI_API_KEYS[0] ?? '');
$res = file_get_contents("https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($key));
$data = json_decode($res, true);
foreach (($data['models'] ?? []) as $m) {
    if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [])) {
        echo $m['name'] . "<br>";
    }
}