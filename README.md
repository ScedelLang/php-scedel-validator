# scedel/validator

<img src="https://raw.githubusercontent.com/ScedelLang/grammar/5f1e7572f328d657c726a2fcaeaf53d9f6863d6a/logo.svg" width="250px" alt="logo" />

Validates JSON payloads against a `SchemaRepository` from `scedel/schema`.

## RFC support

- [Target RFC: `0.14.2`](https://github.com/ScedelLang/grammar/blob/main/RFC-Scedel-0.14.2.md)

## API

```php
use Scedel\Validator\JsonValidator;

$validator = new JsonValidator();
$errors = $validator->validate($json, $repository, 'Root');
```

`validate()` returns `ValidationError[]`.

## CLI

```bash
php scedel-validator/bin/validate-json.php '<json>' /absolute/path/schema.scedel
php scedel-validator/bin/validate-json.php --type Post payload.json /absolute/path/schema.scedel
```

If root type is omitted, validator uses:
1. `Root` type, if present
2. the only type in repository
3. otherwise returns an error with available types.
