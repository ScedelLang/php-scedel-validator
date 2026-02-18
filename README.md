# scedel/validator

Validates JSON payloads against a `SchemaRepository` from `scedel/schema`.

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
