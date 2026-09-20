<?php

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const LATITUDE = -23.5505;
const LONGITUDE = -46.6333;
const TIMEZONE = 'America/Sao_Paulo';
const API_URL = 'https://api.open-meteo.com/v1/forecast';

$mode = strtolower(trim((string) (getenv('WEATHER_MODE') ?: 'hourly')));
$forceDaily = filter_var(getenv('FORCE_DAILY') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$webhookUrl = trim((string) getenv('DISCORD_WEBHOOK_URL'));
$dailyStateFile = __DIR__ . '/.state/last-daily-date.txt';

if (!in_array($mode, ['hourly', 'daily'], true)) {
    throw new RuntimeException('WEATHER_MODE deve ser hourly ou daily.');
}

if ($webhookUrl === '') {
    throw new RuntimeException('DISCORD_WEBHOOK_URL não configurado.');
}

$today = date('Y-m-d');

if (
    $mode === 'daily'
    && !$forceDaily
    && is_file($dailyStateFile)
    && trim((string) file_get_contents($dailyStateFile)) === $today
) {
    echo "A previsão diária de {$today} já foi publicada. Nada a fazer.\n";
    exit(0);
}

function requestJson(string $url): array
{
    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('Não foi possível iniciar o cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: clima-sp-discord/1.0',
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        throw new RuntimeException("Erro ao consultar Open-Meteo. HTTP {$status}. {$error}");
    }

    try {
        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('A Open-Meteo retornou JSON inválido.', 0, $e);
    }

    if (!is_array($data)) {
        throw new RuntimeException('Resposta inesperada da Open-Meteo.');
    }

    return $data;
}

function sendDiscord(string $webhookUrl, array $payload): void
{
    $payload['allowed_mentions'] = ['parse' => []];

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $separator = str_contains($webhookUrl, '?') ? '&' : '?';
        $ch = curl_init($webhookUrl . $separator . 'wait=true');

        if ($ch === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL para o Discord.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $json,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $status >= 200 && $status < 300) {
            return;
        }

        if ($status === 429) {
            $rate = json_decode((string) $response, true);
            $retryAfter = is_array($rate) ? (float) ($rate['retry_after'] ?? 1.5) : 1.5;
            usleep((int) (($retryAfter + 0.25) * 1_000_000));
            continue;
        }

        if ($attempt < 5 && ($status === 0 || $status >= 500)) {
            sleep($attempt);
            continue;
        }

        throw new RuntimeException("Erro ao enviar ao Discord. HTTP {$status}. {$error}");
    }

    throw new RuntimeException('Número máximo de tentativas ao Discord excedido.');
}

function weatherInfo(int $code): array
{
    return match (true) {
        $code === 0 => ['☀️', 'Céu limpo'],
        $code === 1 => ['🌤️', 'Predominantemente limpo'],
        $code === 2 => ['⛅', 'Parcialmente nublado'],
        $code === 3 => ['☁️', 'Nublado'],
        in_array($code, [45, 48], true) => ['🌫️', 'Neblina'],
        in_array($code, [51, 53, 55, 56, 57], true) => ['🌦️', 'Garoa'],
        in_array($code, [61, 63, 66], true) => ['🌧️', 'Chuva'],
        in_array($code, [65, 67], true) => ['🌧️', 'Chuva forte'],
        in_array($code, [71, 73, 75, 77], true) => ['🌨️', 'Neve'],
        in_array($code, [80, 81], true) => ['🌦️', 'Pancadas de chuva'],
        $code === 82 => ['⛈️', 'Pancadas fortes'],
        in_array($code, [85, 86], true) => ['🌨️', 'Pancadas de neve'],
        $code === 95 => ['⛈️', 'Trovoadas'],
        in_array($code, [96, 99], true) => ['⛈️', 'Tempestade com granizo'],
        default => ['🌡️', 'Condição não classificada'],
    };
}

function embedColor(int $code): int
{
    if (in_array($code, [95, 96, 99], true) || $code === 82) {
        return 0xE74C3C;
    }

    if (in_array($code, [51, 53, 55, 56, 57, 61, 63, 65, 66, 67, 80, 81], true)) {
        return 0x3498DB;
    }

    if (in_array($code, [2, 3, 45, 48], true)) {
        return 0x95A5A6;
    }

    return 0xF1C40F;
}

function n(mixed $value, int $decimals = 1): string
{
    if (!is_numeric($value)) {
        return '—';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

function pct(mixed $value): string
{
    return is_numeric($value) ? number_format((float) $value, 0, ',', '.') . '%' : '—';
}

function weekdayPt(string $date): string
{
    $names = [
        1 => 'Segunda-feira',
        2 => 'Terça-feira',
        3 => 'Quarta-feira',
        4 => 'Quinta-feira',
        5 => 'Sexta-feira',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    $dt = new DateTimeImmutable($date, new DateTimeZone(TIMEZONE));

    return $names[(int) $dt->format('N')] ?? '';
}

function brDate(string $date): string
{
    $dt = new DateTimeImmutable($date, new DateTimeZone(TIMEZONE));

    return $dt->format('d/m/Y');
}

function hourFromIso(string $iso): string
{
    $dt = new DateTimeImmutable($iso, new DateTimeZone(TIMEZONE));

    return $dt->format('H:i');
}

function currentHourlyIndex(array $hourly): int
{
    $times = $hourly['time'] ?? [];

    if (!is_array($times) || $times === []) {
        return 0;
    }

    $target = date('Y-m-d\TH:00');

    foreach ($times as $index => $time) {
        if ((string) $time >= $target) {
            return (int) $index;
        }
    }

    return 0;
}

function arrayWindowMax(array $values, int $start, int $length): float
{
    $slice = array_slice($values, $start, $length);
    $numeric = array_values(array_filter($slice, 'is_numeric'));

    return $numeric === [] ? 0.0 : (float) max($numeric);
}

function automaticAlerts(array $data, int $hourIndex): array
{
    $alerts = [];
    $current = is_array($data['current'] ?? null) ? $data['current'] : [];
    $hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];

    $code = (int) ($current['weather_code'] ?? 0);
    $apparent = (float) ($current['apparent_temperature'] ?? 0);
    $humidity = (float) ($current['relative_humidity_2m'] ?? 100);
    $precip = (float) ($current['precipitation'] ?? 0);
    $gust = (float) ($current['wind_gusts_10m'] ?? 0);

    $nextProb = arrayWindowMax($hourly['precipitation_probability'] ?? [], $hourIndex, 3);
    $nextPrecip = arrayWindowMax($hourly['precipitation'] ?? [], $hourIndex, 3);
    $nextGust = arrayWindowMax($hourly['wind_gusts_10m'] ?? [], $hourIndex, 3);
    $nextCodes = array_slice($hourly['weather_code'] ?? [], $hourIndex, 3);

    if (
        in_array($code, [95, 96, 99], true)
        || array_intersect([95, 96, 99], array_map('intval', $nextCodes)) !== []
    ) {
        $alerts[] = '⛈️ **Tempestade/trovoadas:** condição atual ou prevista nas próximas horas.';
    }

    if ($precip >= 7 || $nextPrecip >= 7) {
        $alerts[] = '🌧️ **Chuva forte:** volume horário elevado detectado na previsão.';
    } elseif ($nextProb >= 80 && $nextPrecip >= 2) {
        $alerts[] = '☔ **Chuva muito provável:** chance de precipitação de ' . n($nextProb, 0) . '% nas próximas horas.';
    }

    if (max($gust, $nextGust) >= 60) {
        $alerts[] = '💨 **Rajadas fortes:** podem atingir cerca de ' . n(max($gust, $nextGust), 0) . ' km/h.';
    }

    if ($apparent >= 35) {
        $alerts[] = '🥵 **Calor intenso:** sensação térmica de ' . n($apparent) . '°C.';
    }

    if ($humidity <= 30) {
        $alerts[] = '🏜️ **Umidade muito baixa:** umidade relativa em ' . n($humidity, 0) . '%.';
    }

    return $alerts;
}

function nextHoursText(array $data, int $start, int $count = 3): string
{
    $hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
    $times = $hourly['time'] ?? [];
    $temps = $hourly['temperature_2m'] ?? [];
    $probs = $hourly['precipitation_probability'] ?? [];
    $codes = $hourly['weather_code'] ?? [];

    $lines = [];

    for ($i = $start; $i < min($start + $count, count($times)); $i++) {
        [$icon, $label] = weatherInfo((int) ($codes[$i] ?? 0));

        $lines[] = sprintf(
            '%s **%s** — %s°C • chuva %s • %s',
            $icon,
            hourFromIso((string) $times[$i]),
            n($temps[$i] ?? null),
            pct($probs[$i] ?? null),
            $label
        );
    }

    return $lines === [] ? 'Sem dados horários disponíveis.' : implode("\n", $lines);
}

function dailyAlerts(array $data): array
{
    $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];

    $prob = (float) (($daily['precipitation_probability_max'][0] ?? 0));
    $precip = (float) (($daily['precipitation_sum'][0] ?? 0));
    $gust = (float) (($daily['wind_gusts_10m_max'][0] ?? 0));
    $apparentMax = (float) (($daily['apparent_temperature_max'][0] ?? 0));
    $code = (int) (($daily['weather_code'][0] ?? 0));

    $alerts = [];

    if (in_array($code, [95, 96, 99], true)) {
        $alerts[] = '⛈️ Possibilidade de tempestade/trovoadas ao longo do dia.';
    }

    if ($precip >= 20) {
        $alerts[] = '🌧️ Volume de chuva diário elevado: cerca de ' . n($precip) . ' mm.';
    } elseif ($prob >= 80) {
        $alerts[] = '☔ Chance alta de chuva: ' . n($prob, 0) . '%.';
    }

    if ($gust >= 60) {
        $alerts[] = '💨 Rajadas podem chegar a ' . n($gust, 0) . ' km/h.';
    }

    if ($apparentMax >= 35) {
        $alerts[] = '🥵 Sensação térmica máxima próxima de ' . n($apparentMax) . '°C.';
    }

    return $alerts;
}

$params = [
    'latitude' => LATITUDE,
    'longitude' => LONGITUDE,
    'timezone' => TIMEZONE,
    'forecast_days' => 4,
    'current' => implode(',', [
        'temperature_2m',
        'apparent_temperature',
        'relative_humidity_2m',
        'precipitation',
        'rain',
        'weather_code',
        'cloud_cover',
        'wind_speed_10m',
        'wind_gusts_10m',
        'is_day',
    ]),
    'hourly' => implode(',', [
        'temperature_2m',
        'apparent_temperature',
        'relative_humidity_2m',
        'precipitation_probability',
        'precipitation',
        'weather_code',
        'wind_speed_10m',
        'wind_gusts_10m',
    ]),
    'daily' => implode(',', [
        'weather_code',
        'temperature_2m_max',
        'temperature_2m_min',
        'apparent_temperature_max',
        'apparent_temperature_min',
        'precipitation_sum',
        'precipitation_probability_max',
        'wind_speed_10m_max',
        'wind_gusts_10m_max',
        'sunrise',
        'sunset',
    ]),
];

$url = API_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
$data = requestJson($url . '&_=' . time());

$current = is_array($data['current'] ?? null) ? $data['current'] : [];
$hourly = is_array($data['hourly'] ?? null) ? $data['hourly'] : [];
$daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
$hourIndex = currentHourlyIndex($hourly);

if ($mode === 'hourly') {
    $code = (int) ($current['weather_code'] ?? 0);
    [$icon, $condition] = weatherInfo($code);
    $alerts = automaticAlerts($data, $hourIndex);
    $hourProb = $hourly['precipitation_probability'][$hourIndex] ?? null;

    $notification = $alerts === []
        ? '✅ **Sem aviso automático no momento.**'
        : implode("\n", $alerts);

    sendDiscord($webhookUrl, [
        'embeds' => [[
            'title' => "{$icon} Clima em São Paulo — " . date('H:i'),
            'description' => "**{$condition}**\nSão Paulo, SP",
            'color' => embedColor($code),
            'fields' => [
                [
                    'name' => '🌡️ Agora',
                    'value' =>
                        '**' . n($current['temperature_2m'] ?? null) . "°C**\n"
                        . 'Sensação: ' . n($current['apparent_temperature'] ?? null) . "°C\n"
                        . 'Umidade: ' . pct($current['relative_humidity_2m'] ?? null)
                        . ' • Nuvens: ' . pct($current['cloud_cover'] ?? null),
                    'inline' => true,
                ],
                [
                    'name' => '🌧️ Chuva',
                    'value' =>
                        'Agora: ' . n($current['precipitation'] ?? null) . " mm\n"
                        . 'Probabilidade: ' . pct($hourProb),
                    'inline' => true,
                ],
                [
                    'name' => '💨 Vento',
                    'value' =>
                        n($current['wind_speed_10m'] ?? null) . " km/h\n"
                        . 'Rajadas: ' . n($current['wind_gusts_10m'] ?? null) . ' km/h',
                    'inline' => true,
                ],
                [
                    'name' => '🕒 Próximas 3 horas',
                    'value' => nextHoursText($data, $hourIndex, 3),
                    'inline' => false,
                ],
                [
                    'name' => '🔔 Notificação',
                    'value' => $notification,
                    'inline' => false,
                ],
            ],
            'footer' => [
                'text' => 'Dados: Open-Meteo • Avisos automáticos não são alertas oficiais',
            ],
            'timestamp' => date(DATE_ATOM),
        ]],
    ]);

    echo 'Atualização horária enviada com sucesso.' . PHP_EOL;
    exit(0);
}

$dates = $daily['time'] ?? [];
$codes = $daily['weather_code'] ?? [];

if (!is_array($dates) || $dates === []) {
    throw new RuntimeException('A API não retornou a previsão diária.');
}

$todayCode = (int) ($codes[0] ?? 0);
[$todayIcon, $todayCondition] = weatherInfo($todayCode);
$dailyWarnings = dailyAlerts($data);

$nextDays = [];

for ($i = 1; $i < min(4, count($dates)); $i++) {
    [$dayIcon, $dayCondition] = weatherInfo((int) ($codes[$i] ?? 0));

    $nextDays[] = sprintf(
        '%s **%s (%s)** — %s° / %s°C • chuva %s • %s',
        $dayIcon,
        weekdayPt((string) $dates[$i]),
        brDate((string) $dates[$i]),
        n($daily['temperature_2m_min'][$i] ?? null, 0),
        n($daily['temperature_2m_max'][$i] ?? null, 0),
        pct($daily['precipitation_probability_max'][$i] ?? null),
        $dayCondition
    );
}

$dailyNotice = $dailyWarnings === []
    ? '✅ **Sem aviso automático relevante para hoje.**'
    : implode("\n", $dailyWarnings);

sendDiscord($webhookUrl, [
    'embeds' => [[
        'title' => "{$todayIcon} Previsão do Dia — São Paulo",
        'description' =>
            '**' . weekdayPt((string) $dates[0]) . ', ' . brDate((string) $dates[0]) . "**\n"
            . $todayCondition,
        'color' => embedColor($todayCode),
        'fields' => [
            [
                'name' => '🌡️ Temperaturas',
                'value' =>
                    'Mínima: **' . n($daily['temperature_2m_min'][0] ?? null) . "°C**\n"
                    . 'Máxima: **' . n($daily['temperature_2m_max'][0] ?? null) . "°C**\n"
                    . 'Sensação: '
                    . n($daily['apparent_temperature_min'][0] ?? null) . '° a '
                    . n($daily['apparent_temperature_max'][0] ?? null) . '°C',
                'inline' => true,
            ],
            [
                'name' => '🌧️ Chuva',
                'value' =>
                    'Chance máxima: **' . pct($daily['precipitation_probability_max'][0] ?? null) . "**\n"
                    . 'Volume previsto: **' . n($daily['precipitation_sum'][0] ?? null) . ' mm**',
                'inline' => true,
            ],
            [
                'name' => '💨 Vento',
                'value' =>
                    'Máximo: ' . n($daily['wind_speed_10m_max'][0] ?? null) . " km/h\n"
                    . 'Rajadas: ' . n($daily['wind_gusts_10m_max'][0] ?? null) . ' km/h',
                'inline' => true,
            ],
            [
                'name' => '🌅 Sol',
                'value' =>
                    'Nascer: **' . hourFromIso((string) ($daily['sunrise'][0] ?? date('c'))) . "**\n"
                    . 'Pôr: **' . hourFromIso((string) ($daily['sunset'][0] ?? date('c'))) . '**',
                'inline' => true,
            ],
            [
                'name' => '🔔 Atenção hoje',
                'value' => $dailyNotice,
                'inline' => false,
            ],
            [
                'name' => '📅 Próximos dias',
                'value' => $nextDays === [] ? 'Sem dados.' : implode("\n", $nextDays),
                'inline' => false,
            ],
        ],
        'footer' => [
            'text' => 'Dados: Open-Meteo • São Paulo, SP',
        ],
        'timestamp' => date(DATE_ATOM),
    ]],
]);

if (!is_dir(dirname($dailyStateFile)) && !mkdir(dirname($dailyStateFile), 0775, true) && !is_dir(dirname($dailyStateFile))) {
    throw new RuntimeException('Não foi possível criar o diretório de estado.');
}

if (file_put_contents($dailyStateFile, $today . PHP_EOL) === false) {
    throw new RuntimeException('Não foi possível registrar a previsão diária.');
}

echo 'Previsão diária enviada com sucesso.' . PHP_EOL;
