<?php

declare(strict_types=1);

namespace Scedel\Validator\Tests;

use PHPUnit\Framework\TestCase;
use Scedel\ErrorCategory;
use Scedel\ErrorCode;
use Scedel\Parser\ParserService;
use Scedel\Schema\Infrastructure\FilesystemIncludeResolver;
use Scedel\Schema\Infrastructure\FilesystemSourceLoader;
use Scedel\Schema\Model\SchemaRepository;
use Scedel\Schema\RepositoryBuilder;
use Scedel\Validator\Cli\ValidateJsonCommand;
use Scedel\Validator\JsonValidator;

final class JsonValidatorTest extends TestCase
{
    public function testValidJsonForRecordAndDictPasses(): void
    {
        $schema = <<<'SCED'
        type Root = {
            id: Int(min:1)
            title: String(min:3, max:10)
            tags: String[min:1]
            meta: dict<String, Int>
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $json = '{"id":7,"title":"scedel","tags":["core"],"meta":{"priority":1}}';
        $errors = $validator->validate($json, $repository);

        self::assertSame([], $errors);
    }

    public function testInvalidJsonReturnsDetailedErrors(): void
    {
        $schema = <<<'SCED'
        type Root = {
            id: Int(min:1)
            tags: String[min:1]
            meta: dict<String, Int>
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $json = '{"id":0,"tags":[1],"meta":[],"extra":true}';
        $errors = $validator->validate($json, $repository);

        self::assertNotEmpty($errors);

        $messages = array_map(
            static fn ($error): string => sprintf('%s|%s', $error->path, $error->message),
            $errors,
        );

        self::assertContains('$.id|Constraint "min" failed: expected 0 against 1.', $messages);
        self::assertContains('$.tags[0]|Expected value of type String, got int.', $messages);
        self::assertContains('$.meta|Expected JSON object for dict type.', $messages);
        self::assertContains('$.extra|Unexpected field "extra".', $messages);
    }

    public function testConditionalTypeRespectsSiblingFields(): void
    {
        $schema = <<<'SCED'
        type Root = {
            status: "Rejected" | "Draft"
            rejectReason: when status = "Rejected" then String(min:3) else absent
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $draftWithReason = '{"status":"Draft","rejectReason":"because"}';
        $errorsForDraft = $validator->validate($draftWithReason, $repository);
        self::assertNotEmpty($errorsForDraft);
        self::assertContains(
            ErrorCode::FieldMustBeAbsent,
            array_map(static fn ($error): ErrorCode => $error->code, $errorsForDraft),
        );

        $rejectedWithoutReason = '{"status":"Rejected"}';
        $errorsForRejected = $validator->validate($rejectedWithoutReason, $repository);
        self::assertNotEmpty($errorsForRejected);

        $rejectedWithReason = '{"status":"Rejected","rejectReason":"bad"}';
        $okErrors = $validator->validate($rejectedWithReason, $repository);
        self::assertSame([], $okErrors);
    }

    public function testCustomValidatorWithParameterIsApplied(): void
    {
        $schema = <<<'SCED'
        validator Int(minBound, i:Int = 2) = this >= $i
        type Root = {
            count: Int(minBound(3))
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $valid = '{"count":3}';
        $validErrors = $validator->validate($valid, $repository);
        self::assertSame([], $validErrors);

        $invalid = '{"count":2}';
        $invalidErrors = $validator->validate($invalid, $repository);
        self::assertNotEmpty($invalidErrors);
        self::assertSame('$.count', $invalidErrors[0]->path);
    }

    public function testRootTypeInferenceRequiresDisambiguationWhenMultipleTypesExist(): void
    {
        $schema = <<<'SCED'
        type A = String
        type B = Int
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $errors = $validator->validate('"ok"', $repository);
        self::assertCount(1, $errors);
        self::assertStringContainsString('Unable to infer root type', $errors[0]->message);

        $explicitTypeErrors = $validator->validate('"ok"', $repository, 'A');
        self::assertSame([], $explicitTypeErrors);
    }

    public function testExtendedBuiltinTypesAndValidatorsAreSupported(): void
    {
        $schema = <<<'SCED'
        type Root = {
            i32: Int(min:-10, max:10, greater:-11, less:11)
            u32: Uint(min:0, max:4294967295, greater:-1, less:4294967296)
            i16: Short(min:-32768, max:32767)
            u16: Ushort(min:0, max:65535)
            i64: Long(min:-100, max:100)
            u64: Ulong(min:0, max:100)
            i8: Byte(min:-128, max:127)
            u8: Ubyte(min:0, max:255)
            f32: Float(min:0, max:10, less:11, greater:-1, precision:2)
            f64: Double(min:0, max:10, less:11, greater:-1, precision:4)
            money: Decimal(min:"0.10", max:"20.00", less:"25.00", greater:"0.01", precision:2)
            text: String(min:2, max:8, regex:"^[a-z]+$")
            site: Url(scheme:["https"], domain:["example.com"])
            mail: Email(domain:["example.com"])
            id: Uuid
            blob64: Base64(min:4, max:12)
            date: Date(min:"2024-01-01", max:"2024-12-31", format:"YYYY-MM-DD")
            dt: DateTime(min:"2024-01-01 00:00:00", max:"2024-12-31 23:59:59", format:"YYYY-MM-DD HH:ii:SS")
            tm: Time(min:"09:00", max:"18:00", format:"HH:ii")
            flag: Bool
            alwaysTrue: True
            alwaysFalse: False
            ipAny: Ip(subnet:["10.0.0.0/8"], mask:[32])
            ip4: IpV4(subnet:["192.168.1.0/24"], mask:[32])
            ip6: IpV6(subnet:["2001:db8::/32"], mask:[128])
            none: Null
            bytes: Binary
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $validJson = <<<'JSON'
        {
          "i32": 1,
          "u32": 10,
          "i16": -10,
          "u16": 10,
          "i64": 42,
          "u64": 42,
          "i8": -8,
          "u8": 8,
          "f32": 1.25,
          "f64": 1.2345,
          "money": "10.25",
          "text": "scedel",
          "site": "https://api.example.com/v1",
          "mail": "user@example.com",
          "id": "123e4567-e89b-12d3-a456-426614174000",
          "blob64": "QUJDRA==",
          "date": "2024-06-11",
          "dt": "2024-06-11 12:30:45",
          "tm": "12:30",
          "flag": true,
          "alwaysTrue": true,
          "alwaysFalse": false,
          "ipAny": "10.10.10.10",
          "ip4": "192.168.1.10",
          "ip6": "2001:db8::1",
          "none": null,
          "bytes": "raw-binary-like-string"
        }
        JSON;

        $validErrors = $validator->validate($validJson, $repository);
        self::assertSame([], $validErrors);

        $invalidJson = <<<'JSON'
        {
          "i32": 1,
          "u32": 10,
          "i16": -10,
          "u16": 10,
          "i64": 42,
          "u64": 42,
          "i8": -8,
          "u8": 8,
          "f32": 1.234,
          "f64": 1.2345,
          "money": "10.25",
          "text": "Bad",
          "site": "http://api.example.com/v1",
          "mail": "user@not-example.org",
          "id": "123e4567-e89b-12d3-a456-426614174000",
          "blob64": "QUJDRA==",
          "date": "2024-06-11",
          "dt": "2024-06-11 12:30:45",
          "tm": "12:30",
          "flag": true,
          "alwaysTrue": true,
          "alwaysFalse": false,
          "ipAny": "11.10.10.10",
          "ip4": "192.168.1.10",
          "ip6": "2001:db8::1",
          "none": null,
          "bytes": "raw-binary-like-string"
        }
        JSON;

        $invalidErrors = $validator->validate($invalidJson, $repository);
        self::assertNotEmpty($invalidErrors);

        $messages = array_map(
            static fn ($error): string => sprintf('%s|%s', $error->path, $error->message),
            $invalidErrors,
        );

        self::assertContains('$.f32|Constraint "precision" failed: expected 1.234 against 2.', $messages);
        self::assertContains('$.text|Constraint "regex" failed: expected "Bad" against "^[a-z]+$".', $messages);
        self::assertContains('$.site|Constraint "scheme" failed: expected "http://api.example.com/v1" against ["https"].', $messages);
        self::assertContains('$.mail|Constraint "domain" failed: expected "user@not-example.org" against ["example.com"].', $messages);
        self::assertContains('$.ipAny|Constraint "subnet" failed: expected "11.10.10.10" against ["10.0.0.0/8"].', $messages);
    }

    public function testCliCommandAcceptsJsonFileAndSchemaPath(): void
    {
        $tmp = $this->createTempDir();
        $schemaPath = $tmp . '/schema.scedel';
        $jsonPath = $tmp . '/payload.json';

        file_put_contents($schemaPath, "type Root = {id:Int}\n");
        file_put_contents($jsonPath, "{\"id\":42}\n");

        $command = new ValidateJsonCommand();

        ob_start();
        $exitCode = $command->run(['validate-json', $jsonPath, $schemaPath]);
        ob_end_clean();

        self::assertSame(0, $exitCode);
    }

    public function testDateTimeAndDurationArithmeticInConstraints(): void
    {
        $schema = <<<'SCED'
        type Root = {
            startsAt: DateTime
            endsAt: DateTime(
                min: this.startsAt + 1h,
                max: this.startsAt + 30d
            )
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $valid = '{"startsAt":"2026-01-01 10:00:00","endsAt":"2026-01-01 11:00:00"}';
        self::assertSame([], $validator->validate($valid, $repository));

        $invalid = '{"startsAt":"2026-01-01 10:00:00","endsAt":"2026-01-01 10:30:00"}';
        $errors = $validator->validate($invalid, $repository);
        self::assertNotEmpty($errors);
        self::assertSame('$.endsAt', $errors[0]->path);
    }

    public function testInvalidArithmeticInConstraintArgumentIsReported(): void
    {
        $schema = <<<'SCED'
        type Root = {
            value: Int(min: 1 / 0)
        }
        SCED;

        $repository = $this->createRepository($schema);
        $validator = new JsonValidator();

        $errors = $validator->validate('{"value":5}', $repository);
        self::assertNotEmpty($errors);
        self::assertSame(\Scedel\ErrorCode::InvalidArithmetic, $errors[0]->code);
    }

    public function testUnknownConstraintProducesSemanticError(): void
    {
        $schema = <<<'SCED'
        type Root = {
            value: Int(unknownRule: 1)
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":5}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::UnknownConstraint, $errors[0]->code);
        self::assertSame(ErrorCategory::SemanticError, $errors[0]->category);
    }

    public function testCustomValidatorMissingArgumentIsReported(): void
    {
        $schema = <<<'SCED'
        validator Int(range, from:Int, to:Int) = this > $from and this < $to
        type Root = {
            value: Int(range(10))
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":12}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::MissingArgument, $errors[0]->code);
        self::assertSame('$.value', $errors[0]->path);
    }

    public function testCustomValidatorTooManyArgumentsIsReported(): void
    {
        $schema = <<<'SCED'
        validator Int(range, from:Int, to:Int) = this > $from and this < $to
        type Root = {
            value: Int(range(1, 10, 20))
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":12}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::TooManyArguments, $errors[0]->code);
    }

    public function testCustomValidatorUnknownNamedArgumentIsReported(): void
    {
        $schema = <<<'SCED'
        validator Int(range, from:Int, to:Int) = this > $from and this < $to
        type Root = {
            value: Int(range(start:1, to:10))
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":12}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::UnknownArgumentName, $errors[0]->code);
    }

    public function testCustomValidatorDuplicateArgumentIsReported(): void
    {
        $schema = <<<'SCED'
        validator Int(range, from:Int, to:Int) = this > $from and this < $to
        type Root = {
            value: Int(range(1, from:2, to:10))
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":12}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::DuplicateArgument, $errors[0]->code);
    }

    public function testPositionalArgumentAfterNamedIsRejected(): void
    {
        $schema = <<<'SCED'
        validator Int(range, from:Int, to:Int) = this > $from and this < $to
        type Root = {
            value: Int(range(from:1, 10))
        }
        SCED;

        $errors = (new JsonValidator())->validate('{"value":12}', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::UnknownArgumentName, $errors[0]->code);
    }

    public function testParentReferenceAtRootProducesParentUndefined(): void
    {
        $schema = <<<'SCED'
        type Root = DateTime(min: parent.startsAt)
        SCED;

        $errors = (new JsonValidator())->validate('"2026-01-01 10:00:00"', $this->createRepository($schema));

        self::assertNotEmpty($errors);
        self::assertSame(ErrorCode::ParentUndefined, $errors[0]->code);
        self::assertSame(ErrorCategory::TypeError, $errors[0]->category);
    }

    public function testValidatorDeclaresSupportedRfcVersion(): void
    {
        self::assertContains('0.14.2', JsonValidator::SUPPORTED_RFC_VERSIONS);
    }

    private function createRepository(string $source): SchemaRepository
    {
        $builder = new RepositoryBuilder(
            new ParserService(),
            new FilesystemIncludeResolver(),
            new FilesystemSourceLoader(),
        );

        return $builder->buildFromString($source, 'inline.scedel');
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/scedel-validator-' . bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }
}
