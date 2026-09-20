# Clima SP → Discord

Automação em PHP + GitHub Actions para publicar o clima de **São Paulo, SP** em um canal do Discord.

## O que publica

### A cada hora
- temperatura atual;
- sensação térmica;
- umidade;
- condição do tempo;
- chuva atual;
- probabilidade de chuva nas próximas horas;
- vento e rajadas;
- previsão resumida das próximas 3 horas;
- aviso automático quando houver chuva forte, tempestade, vento forte, calor intenso ou umidade muito baixa.

### Previsão diária
Executada de manhã com:
- mínima e máxima;
- sensação térmica mínima e máxima;
- probabilidade e volume de chuva;
- vento e rajadas;
- nascer e pôr do sol;
- resumo dos próximos dias.

## Fonte dos dados

Open-Meteo Forecast API:

https://api.open-meteo.com/v1/forecast

Local usado:

- São Paulo, SP
- Latitude: -23.5505
- Longitude: -46.6333
- Timezone: America/Sao_Paulo

## Configuração do Discord

Crie um webhook no canal de clima do Discord.

Depois, neste repositório:

**Settings → Secrets and variables → Actions → Secrets → New repository secret**

Crie:

- Nome: `DISCORD_WEBHOOK_URL`
- Valor: URL completa do webhook do canal de clima

Nunca coloque a URL do webhook diretamente no código ou em uma variável pública.

## GitHub Actions

### Clima horário
Arquivo:

`.github/workflows/clima-hourly.yml`

Roda a cada hora, no minuto 17.

### Previsão diária
Arquivo:

`.github/workflows/clima-daily.yml`

Tenta publicar às 06:07 e novamente às 06:27 no horário de São Paulo. O segundo horário funciona como contingência; o script registra a data e evita duplicação.

## Avisos

Os avisos exibidos pela automação são derivados dos dados meteorológicos da previsão e **não substituem alertas oficiais de Defesa Civil, INMET ou outros órgãos públicos**.
