# Changelog

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování se řídí [SemVer](https://semver.org/lang/cs/).

## [1.2.1] – 2026-08-10

### Opraveno

- **ETag byl nestabilní, takže odpověď `304` prakticky nikdy nenastala.**
  Počítal se z celého těla včetně `generatedAt` (mění se každou sekundu)
  a metrik opcache `opcacheMemoryUsedPercent` a `opcacheHitRate` (rostou
  s každým načteným skriptem). Nově se tahle tři pole z výpočtu vynechávají —
  ETag tedy identifikuje stav serveru, ne konkrétní bajty těla.

### Změněno

- Testy nehlásí rizikový stav kvůli deprecation hláškám, které starší verze
  Symfony vypisují na novějším PHP.

## [1.2.0] – 2026-08-10

### Přidáno

- Příkaz `bin/console ewebovky:status` s přepínači `--json` a `--host`.
  Vyžaduje `symfony/console`; bez ní se jen nezaregistruje.
- Nová pole ve výstupu: `bundleVersion`, `phpMemoryLimit`, `phpUploadMaxFilesize`,
  `phpPostMaxSize`, `phpExtensions` a šestice `opcache*`
  (zapnutí, vytížení paměti, hit rate, počty restartů).
- Konfigurovatelná cesta endpointu (`ewebovky_status.path`, výchozí `/status.json`).
- Soubor `Resources/config/routes.php` pro import rout jedním řádkem.
- Hlavičky `X-Robots-Tag: noindex, nofollow` a `X-Content-Type-Options: nosniff`.
- Testovací sada (PHPUnit), statická analýza (PHPStan level 8) a CI matice
  PHP 8.1–8.4 × Symfony 6.4 / 7.x / 8.0.

### Opraveno

- Detekce OS už neshodí endpoint na hostinzích se zakázaným `shell_exec`.
  Od PHP 8 je volání zakázané funkce `Error`, který operátor `@` nezachytí.
- `foreach` nad výsledkem `file('/etc/os-release')` mohl skončit fatální chybou,
  když čtení selhalo mezi kontrolou práv a otevřením souboru.
- `ETag` nyní odpovídá skutečně odeslanému tělu. Dřív se počítal z jiných dat,
  než která odešla, takže podmíněné dotazy nikdy netrefily `304`.
- Podmíněné dotazy zvládají seznam hodnot v `If-None-Match`, `*` i slabé ETagy
  a nevracejí `304` na necacheovatelné metody.
- Extrakce IP adresy serveru zvládá IPv6. Chování pro IPv4 se nemění.
- Detekce OS se provádí jednou za request místo dvakrát.

### Změněno

- Odpověď se už nekóduje třikrát — diakritika a lomítka zůstávají neescapované,
  tělo je zhruba o 15 % menší. Data po dekódování jsou identická.
- Bundle používá `AbstractBundle`; `Configuration.php` a `EwebovkyStatusExtension.php`
  nahradila jedna třída. Alias `ewebovky_status` ani struktura konfigurace se nemění.
- Z `composer.json` zmizelo pole `version`, verze se řídí git tagy.

## [1.1.1] a starší

Historie viz [commity](https://github.com/Ewebovky/status-bundle/commits/main).

[1.2.1]: https://github.com/Ewebovky/status-bundle/compare/1.2.0...1.2.1
[1.2.0]: https://github.com/Ewebovky/status-bundle/compare/1.1.1...1.2.0
[1.1.1]: https://github.com/Ewebovky/status-bundle/releases/tag/1.1.1
