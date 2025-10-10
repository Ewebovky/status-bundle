# Ewebovky Status Bundle

Lehký Symfony bundle, který poskytuje endpoint `/status.json` chráněný tokenem a vrací systémové informace (verze PHP, Symfony, DB aj.)

## Instalace

```bash
composer config --json extra.symfony.endpoint '["flex://defaults","https://raw.githubusercontent.com/Ewebovky/recipes/main/index.json"]'
composer config extra.symfony.allow-contrib true
composer clear-cache
composer require ewebovky/status-bundle:^1.0
```

Poté (pokud není auto-registrace) do `config/bundles.php`:
```php
return [
    Ewebovky\StatusBundle\EwebovkyStatusBundle::class => ['all' => true],
];
```

### Konfigurace

Do `.env` zadejte token:
```
SITE_STATUS_TOKEN=xxxxxxx
```

A vytvořte `config/packages/ewebovky_status.yaml`:
```yaml
ewebovky_status:
  token: '%env(string:SITE_STATUS_TOKEN)%'
```

### Volání endpointu

```
GET /status.json
  Authorization: Bearer xxxxxxx
  – nebo –
GET /status.json
  X-Status-Token: xxxxxxx
  – nebo –
GET /status.json?token=xxxxxxx
```