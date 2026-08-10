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

Bez nastaveného tokenu se endpoint chová fail-closed: vrací `403` a zbytek webu nijak neovlivní.

### Registrace routy

Routu přidá Flex recipe. Pokud recipe neproběhla, vytvořte `config/routes/ewebovky_status.yaml`:
```yaml
ewebovky_status:
    resource: '@EwebovkyStatusBundle/Resources/config/routes.php'
```

Bez tohohle kroku endpoint neexistuje a vrací `404` — samotná instalace bundlu routu nezaregistruje.
Ověření: `bin/console debug:router ewebovky_status_json`.

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

Odpověď nese `ETag`, takže při opakovaném dotazu s `If-None-Match` vrátí `304` bez těla.

### Výpis z příkazové řádky

Stejná data jde získat i bez HTTP a bez tokenu — hodí se do deploy skriptů a při ladění.
Vyžaduje `symfony/console`; bez ní se příkaz jen nezaregistruje.

```bash
bin/console ewebovky:status
bin/console ewebovky:status --json | jq .phpVersion
bin/console ewebovky:status --host=muj-web.cz
```

## Vývoj

```bash
composer install
composer test         # PHPUnit
composer phpstan      # statická analýza
```

## Autor

**Václav Pavlů** – [Ewebovky](https://www.ewebovky.cz), pavlu@ewebovky.cz

Zdrojový kód: [github.com/Ewebovky/status-bundle](https://github.com/Ewebovky/status-bundle)

## Licence

Vydáno pod licencí [MIT](LICENSE).