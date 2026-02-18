<?php

declare(strict_types=1);

namespace Scedel\Validator;

use DateTimeImmutable;
use JsonException;
use Scedel\Ast\AbsentTypeNode;
use Scedel\Ast\ArithmeticOperator;
use Scedel\Ast\ArrayTypeNode;
use Scedel\Ast\ArgValueNode;
use Scedel\Ast\BinaryArithmeticExprNode;
use Scedel\Ast\BinaryPredicateNode;
use Scedel\Ast\BinaryPredicateOperator;
use Scedel\Ast\BoolLiteralNode;
use Scedel\Ast\CompareOperator;
use Scedel\Ast\ComparePredicateNode;
use Scedel\Ast\ConditionalTypeNode;
use Scedel\Ast\ConstraintCallArgNode;
use Scedel\Ast\ConstraintNode;
use Scedel\Ast\DictTypeNode;
use Scedel\Ast\DurationLiteralNode;
use Scedel\Ast\EmptyArrayExprNode;
use Scedel\Ast\ExpressionNode;
use Scedel\Ast\FieldNode;
use Scedel\Ast\FunctionCallExprNode;
use Scedel\Ast\IntersectionTypeNode;
use Scedel\Ast\ListArgNode;
use Scedel\Ast\LiteralNode;
use Scedel\Ast\LiteralTypeNode;
use Scedel\Ast\MatchesPredicateNode;
use Scedel\Ast\NamedTypeNode;
use Scedel\Ast\NotPredicateNode;
use Scedel\Ast\NullLiteralNode;
use Scedel\Ast\NullableNamedTypeNode;
use Scedel\Ast\NullableTypeNode;
use Scedel\Ast\NumberLiteralNode;
use Scedel\Ast\ObjectValidatorBodyNode;
use Scedel\Ast\PathNode;
use Scedel\Ast\PathRootKind;
use Scedel\Ast\PredicateRuleExprNode;
use Scedel\Ast\PredicateValidatorBodyNode;
use Scedel\Ast\RecordTypeNode;
use Scedel\Ast\RegexRuleExprNode;
use Scedel\Ast\RegexValidatorBodyNode;
use Scedel\Ast\SingleArgNode;
use Scedel\Ast\StringLiteralNode;
use Scedel\Ast\TypeExprNode;
use Scedel\Ast\UnaryArithmeticExprNode;
use Scedel\Ast\UnaryArithmeticOperator;
use Scedel\Ast\UnionTypeNode;
use Scedel\ErrorCategory;
use Scedel\ErrorCode;
use Scedel\Schema\Model\BuiltinTypeDefinition;
use Scedel\Schema\Model\BuiltinValidatorDefinition;
use Scedel\Schema\Model\SchemaRepository;
use Scedel\Schema\Model\TypeDefinition;
use Scedel\Schema\Model\ValidatorDefinition;
use Scedel\Validator\Internal\ValidationScope;

final class JsonValidator
{
    public const array SUPPORTED_RFC_VERSIONS = ['0.14.2'];

    private const int MAX_TYPE_RECURSION = 64;
    private ?ErrorCode $lastEvaluationErrorCode = null;

    /**
     * @return ValidationError[]
     */
    public function validate(mixed $json, SchemaRepository $repository, ?string $rootType = null): array
    {
        $errors = [];
        $value = $this->normalizeJsonInput($json, $errors);
        if ($errors !== []) {
            return $errors;
        }

        $effectiveRootType = $this->resolveRootType($repository, $rootType, $errors);
        if ($effectiveRootType === null) {
            return $errors;
        }

        $scope = new ValidationScope(root: $value, current: $value, parent: null);

        $this->validateNamedTypeByName(
            value: $value,
            typeName: $effectiveRootType,
            path: '$',
            scope: $scope,
            repository: $repository,
            errors: $errors,
            typeStack: [],
        );

        return $errors;
    }

    /**
     * @param ValidationError[] $errors
     * @return mixed
     */
    private function normalizeJsonInput(mixed $json, array &$errors): mixed
    {
        if (!is_string($json)) {
            return $json;
        }

        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $errors[] = new ValidationError(
                '$',
                'Invalid JSON: ' . $exception->getMessage(),
                ErrorCode::InvalidExpression,
                ErrorCategory::ParseError,
            );
            return null;
        }
    }

    /**
     * @param ValidationError[] $errors
     */
    private function resolveRootType(SchemaRepository $repository, ?string $requestedType, array &$errors): ?string
    {
        if ($requestedType !== null) {
            if ($repository->getType($requestedType) !== null) {
                return $requestedType;
            }

            $errors[] = new ValidationError(
                '$',
                sprintf('Requested root type "%s" is not defined.', $requestedType),
                ErrorCode::UnknownType,
                ErrorCategory::TypeError,
            );
            return null;
        }

        if ($repository->getType('Root') !== null) {
            return 'Root';
        }

        $types = $repository->customTypes();
        if (count($types) === 1) {
            return $types[0]->name();
        }

        $typeNames = array_map(static fn (TypeDefinition $type): string => $type->name(), $types);
        sort($typeNames);

        $errors[] = new ValidationError(
            '$',
            sprintf(
                'Unable to infer root type. Add type "Root", define a single type, or pass explicit type. Available types: %s',
                implode(', ', $typeNames),
            ),
            ErrorCode::UnknownType,
            ErrorCategory::TypeError,
        );

        return null;
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     */
    private function validateType(
        mixed $value,
        TypeExprNode $type,
        string $path,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        if ($type instanceof AbsentTypeNode) {
            $errors[] = new ValidationError(
                $path,
                'Value must be absent.',
                ErrorCode::FieldMustBeAbsent,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        if ($type instanceof LiteralTypeNode) {
            return $this->validateLiteralType($value, $type, $path, $errors);
        }

        if ($type instanceof NamedTypeNode) {
            $valid = $this->validateNamedTypeByName(
                typeName: $type->name,
                value: $value,
                path: $path,
                scope: $scope,
                repository: $repository,
                errors: $errors,
                typeStack: $typeStack,
            );

            if (!$valid) {
                return false;
            }

            return $this->applyConstraints(
                constraints: $type->constraints,
                targetType: $type->name,
                value: $value,
                path: $path,
                scope: $scope,
                repository: $repository,
                errors: $errors,
                typeStack: $typeStack,
            );
        }

        if ($type instanceof NullableNamedTypeNode) {
            if ($value === null) {
                return true;
            }

            return $this->validateNamedTypeByName(
                typeName: $type->name,
                value: $value,
                path: $path,
                scope: $scope,
                repository: $repository,
                errors: $errors,
                typeStack: $typeStack,
            );
        }

        if ($type instanceof NullableTypeNode) {
            if ($value === null) {
                return true;
            }

            return $this->validateType(
                value: $value,
                type: $type->innerType,
                path: $path,
                scope: $scope,
                repository: $repository,
                errors: $errors,
                typeStack: $typeStack,
            );
        }

        if ($type instanceof ArrayTypeNode) {
            if (!is_array($value) || !array_is_list($value)) {
                $errors[] = new ValidationError($path, 'Expected JSON array.');
                return false;
            }

            $valid = true;
            foreach ($value as $index => $item) {
                if (!$this->validateType(
                    value: $item,
                    type: $type->itemType,
                    path: sprintf('%s[%d]', $path, $index),
                    scope: $scope->child($item),
                    repository: $repository,
                    errors: $errors,
                    typeStack: $typeStack,
                )) {
                    $valid = false;
                }
            }

            $constraintsValid = $this->applyConstraints(
                constraints: $type->constraints,
                targetType: 'Array',
                value: $value,
                path: $path,
                scope: $scope,
                repository: $repository,
                errors: $errors,
                typeStack: $typeStack,
            );

            return $valid && $constraintsValid;
        }

        if ($type instanceof RecordTypeNode) {
            return $this->validateRecordType($value, $type, $path, $scope, $repository, $errors, $typeStack);
        }

        if ($type instanceof DictTypeNode) {
            if (!$this->isObjectLike($value)) {
                $errors[] = new ValidationError($path, 'Expected JSON object for dict type.');
                return false;
            }

            $valid = true;
            foreach ($this->objectKeys($value) as $key) {
                $entryValue = $this->objectGet($value, $key);

                if (!$this->validateType(
                    value: $key,
                    type: $type->keyType,
                    path: sprintf('%s.{key:%s}', $path, $key),
                    scope: $scope->child($key),
                    repository: $repository,
                    errors: $errors,
                    typeStack: $typeStack,
                )) {
                    $valid = false;
                }

                if (!$this->validateType(
                    value: $entryValue,
                    type: $type->valueType,
                    path: $path . '.' . $key,
                    scope: $scope->child($entryValue),
                    repository: $repository,
                    errors: $errors,
                    typeStack: $typeStack,
                )) {
                    $valid = false;
                }
            }

            return $valid;
        }

        if ($type instanceof UnionTypeNode) {
            foreach ($type->items as $itemType) {
                $branchErrors = [];
                if ($this->validateType(
                    value: $value,
                    type: $itemType,
                    path: $path,
                    scope: $scope,
                    repository: $repository,
                    errors: $branchErrors,
                    typeStack: $typeStack,
                )) {
                    return true;
                }
            }

            $errors[] = new ValidationError($path, 'Value does not match any union branch.');
            return false;
        }

        if ($type instanceof IntersectionTypeNode) {
            $valid = true;
            foreach ($type->items as $itemType) {
                if (!$this->validateType(
                    value: $value,
                    type: $itemType,
                    path: $path,
                    scope: $scope,
                    repository: $repository,
                    errors: $errors,
                    typeStack: $typeStack,
                )) {
                    $valid = false;
                }
            }

            return $valid;
        }

        if ($type instanceof ConditionalTypeNode) {
            $condition = $this->evaluatePredicate($type->condition, $scope);

            if ($condition === true) {
                return $this->validateType($value, $type->thenType, $path, $scope, $repository, $errors, $typeStack);
            }

            if ($condition === false) {
                return $this->validateType($value, $type->elseType, $path, $scope, $repository, $errors, $typeStack);
            }

            $thenErrors = [];
            if ($this->validateType($value, $type->thenType, $path, $scope, $repository, $thenErrors, $typeStack)) {
                return true;
            }

            $elseErrors = [];
            if ($this->validateType($value, $type->elseType, $path, $scope, $repository, $elseErrors, $typeStack)) {
                return true;
            }

            $errors[] = new ValidationError($path, 'Value does not satisfy conditional type.');
            return false;
        }

        $errors[] = new ValidationError($path, 'Unsupported type node: ' . $type::class);
        return false;
    }

    /**
     * @param ValidationError[] $errors
     */
    private function validateLiteralType(mixed $value, LiteralTypeNode $type, string $path, array &$errors): bool
    {
        $expected = $this->literalToPhpValue($type->literal);
        if ($value === $expected) {
            return true;
        }

        $errors[] = new ValidationError(
            $path,
            sprintf(
                'Expected literal %s, got %s.',
                $this->stringifyValue($expected),
                $this->stringifyValue($value),
            ),
        );

        return false;
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     */
    private function validateNamedTypeByName(
        string $typeName,
        mixed $value,
        string $path,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        $typeDefinition = $repository->getType($typeName);
        if ($typeDefinition === null) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Unknown type "%s".', $typeName),
                ErrorCode::UnknownType,
                ErrorCategory::TypeError,
            );
            return false;
        }

        if ($typeDefinition instanceof BuiltinTypeDefinition) {
            $matches = $typeDefinition->matches($value);
            if (!$matches) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf('Expected value of type %s, got %s.', $typeName, $this->valueType($value)),
                );
            }

            return $matches;
        }

        if (!$typeDefinition instanceof TypeDefinition) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Unknown type "%s".', $typeName),
                ErrorCode::UnknownType,
                ErrorCategory::TypeError,
            );
            return false;
        }

        $nextDepth = ($typeStack[$typeName] ?? 0) + 1;
        if ($nextDepth > self::MAX_TYPE_RECURSION) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Type recursion depth limit exceeded while resolving "%s".', $typeName),
            );
            return false;
        }

        $typeStack[$typeName] = $nextDepth;

        return $this->validateType(
            value: $value,
            type: $typeDefinition->expr,
            path: $path,
            scope: $scope,
            repository: $repository,
            errors: $errors,
            typeStack: $typeStack,
        );
    }

    /**
     * @param ValidationError[] $errors
     * @param ConstraintNode[] $constraints
     * @param array<string, int> $typeStack
     */
    private function applyConstraints(
        array $constraints,
        string $targetType,
        mixed $value,
        string $path,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        $valid = true;

        foreach ($constraints as $constraint) {
            $validator = $repository->getValidator($targetType, $constraint->name);
            if ($validator === null) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf('Unknown constraint "%s" for type "%s".', $constraint->name, $targetType),
                    ErrorCode::UnknownConstraint,
                    ErrorCategory::SemanticError,
                );
                $valid = false;
                continue;
            }

            if ($validator instanceof BuiltinValidatorDefinition) {
                $valid = $valid && $this->applyBuiltinValidatorConstraint(
                    constraint: $constraint,
                    validator: $validator,
                    value: $value,
                    path: $path,
                    scope: $scope,
                    errors: $errors,
                );
                continue;
            }

            if ($validator instanceof ValidatorDefinition) {
                $valid = $valid && $this->applyCustomValidatorConstraint(
                    constraint: $constraint,
                    validator: $validator,
                    value: $value,
                    path: $path,
                    scope: $scope,
                    repository: $repository,
                    errors: $errors,
                    typeStack: $typeStack,
                );
                continue;
            }

            $errors[] = new ValidationError(
                $path,
                sprintf('Unsupported validator type for constraint "%s".', $constraint->name),
            );
            $valid = false;
        }

        return $valid;
    }

    /**
     * @param ValidationError[] $errors
     */
    private function applyBuiltinValidatorConstraint(
        ConstraintNode $constraint,
        BuiltinValidatorDefinition $validator,
        mixed $value,
        string $path,
        ValidationScope $scope,
        array &$errors,
    ): bool {
        $hasArgument = false;
        $argument = null;

        if ($constraint->usesCallSyntax) {
            if (count($constraint->callArgs) > 1) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf('Constraint "%s" accepts only one argument.', $constraint->name),
                    ErrorCode::TooManyArguments,
                    ErrorCategory::ValidationError,
                );
                return false;
            }

            if ($constraint->callArgs !== []) {
                $callArg = $constraint->callArgs[0];
                if ($callArg->name !== null) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Constraint "%s" does not support named arguments.', $constraint->name),
                        ErrorCode::UnknownArgumentName,
                        ErrorCategory::ValidationError,
                    );
                    return false;
                }

                [$resolved, $argument] = $this->evaluateExpression($callArg->value, $scope);
                if (!$resolved) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Constraint "%s" has unsupported argument.', $constraint->name),
                        $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression,
                        ErrorCategory::TypeError,
                    );
                    return false;
                }

                $hasArgument = true;
            }
        } elseif ($constraint->arg !== null) {
            [$resolved, $argument] = $this->evaluateConstraintArgument($constraint->arg, $scope);
            if (!$resolved) {
                $code = $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression;
                $message = $code === ErrorCode::ParentUndefined
                    ? 'Constraint argument references parent in root scope.'
                    : sprintf('Constraint "%s" has unsupported argument.', $constraint->name);

                $errors[] = new ValidationError($path, $message, $code, ErrorCategory::TypeError);
                return false;
            }

            $hasArgument = true;
        }

        if ($validator->requiresArgument && !$hasArgument) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Constraint "%s" requires an argument.', $constraint->name),
                ErrorCode::MissingArgument,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        $result = $validator->evaluate($value, $argument);
        if ($result === null) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Constraint "%s" is not supported for current value.', $constraint->name),
                ErrorCode::ConstraintViolation,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        if ($constraint->negated) {
            $result = !$result;
        }

        if (!$result) {
            $errors[] = new ValidationError(
                $path,
                sprintf(
                    'Constraint "%s" failed: expected %s against %s.',
                    $constraint->name,
                    $this->stringifyValue($value),
                    $this->stringifyValue($argument),
                ),
                ErrorCode::ConstraintViolation,
                ErrorCategory::ValidationError,
            );
        }

        return $result;
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     */
    private function applyCustomValidatorConstraint(
        ConstraintNode $constraint,
        ValidatorDefinition $validator,
        mixed $value,
        string $path,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        $argumentValues = $this->bindValidatorArguments(
            constraint: $constraint,
            validator: $validator,
            scope: $scope,
            path: $path,
            repository: $repository,
            errors: $errors,
            typeStack: $typeStack,
        );

        if ($argumentValues === null) {
            return false;
        }

        $validatorVariables = $scope->variables;
        foreach ($argumentValues as $name => $argumentValue) {
            $validatorVariables['$' . $name] = $argumentValue;
            $validatorVariables[$name] = $argumentValue;
        }

        $validatorScope = new ValidationScope(
            root: $scope->root,
            current: $value,
            parent: $scope->current,
            variables: $validatorVariables,
        );

        $message = sprintf('Constraint "%s" failed.', $constraint->name);
        $result = null;

        if ($validator->body instanceof RegexValidatorBodyNode) {
            $message = sprintf('Validator "%s(%s)" failed.', $validator->targetType, $validator->name);
            $result = $this->evaluateRegexValidatorBody($validator->body, $value, $validatorScope);
        } elseif ($validator->body instanceof PredicateValidatorBodyNode) {
            $message = sprintf('Validator "%s(%s)" failed.', $validator->targetType, $validator->name);
            $result = $this->evaluatePredicate($validator->body->predicate, $validatorScope);
        } elseif ($validator->body instanceof ObjectValidatorBodyNode) {
            $message = $validator->body->message;
            if ($validator->body->rule instanceof RegexRuleExprNode) {
                $result = $this->evaluateRegexRule($validator->body->rule, $value, $validatorScope);
            } elseif ($validator->body->rule instanceof PredicateRuleExprNode) {
                $result = $this->evaluatePredicate($validator->body->rule->predicate, $validatorScope);
            }
        }

        if ($result === null) {
            $errors[] = new ValidationError(
                $path,
                sprintf('Validator "%s(%s)" cannot be evaluated by current runtime.', $validator->targetType, $validator->name),
                ErrorCode::ValidatorFailed,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        if ($constraint->negated) {
            $result = !$result;
        }

        if (!$result) {
            $errors[] = new ValidationError(
                $path,
                $message,
                ErrorCode::ValidatorFailed,
                ErrorCategory::ValidationError,
            );
        }

        return $result;
    }

    private function evaluateRegexValidatorBody(RegexValidatorBodyNode $body, mixed $value, ValidationScope $scope): ?bool
    {
        if (!is_string($value)) {
            return false;
        }

        $pattern = $this->injectVariablesIntoPattern($body->pattern, $scope->variables);
        $match = $this->matchesRegexPattern($value, $pattern);
        if ($match === null) {
            return null;
        }

        return $body->negated ? !$match : $match;
    }

    private function evaluateRegexRule(RegexRuleExprNode $rule, mixed $value, ValidationScope $scope): ?bool
    {
        if (!is_string($value)) {
            return false;
        }

        $pattern = $this->injectVariablesIntoPattern($rule->pattern, $scope->variables);
        $match = $this->matchesRegexPattern($value, $pattern);
        if ($match === null) {
            return null;
        }

        return $rule->negated ? !$match : $match;
    }

    private function injectVariablesIntoPattern(string $pattern, array $variables): string
    {
        foreach ($variables as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $placeholder = str_starts_with($key, '$') ? $key : '$' . $key;
            $pattern = str_replace($placeholder, (string) $value, $pattern);
        }

        return $pattern;
    }

    private function matchesRegexPattern(string $value, string $pattern): ?bool
    {
        $regex = '/' . str_replace('/', '\/', $pattern) . '/u';
        $result = @preg_match($regex, $value);

        if ($result === false) {
            return null;
        }

        return $result === 1;
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     * @return array<string, mixed>|null
     */
    private function bindValidatorArguments(
        ConstraintNode $constraint,
        ValidatorDefinition $validator,
        ValidationScope $scope,
        string $path,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): ?array {
        $paramIndexByName = [];
        foreach ($validator->params as $index => $param) {
            $paramIndexByName[$param->name] = $index;
        }

        /** @var array<string, mixed> $provided */
        $provided = [];

        if ($constraint->usesCallSyntax) {
            $namedSectionStarted = false;
            $nextPositionalIndex = 0;

            foreach ($constraint->callArgs as $callArg) {
                [$resolved, $resolvedValue] = $this->evaluateExpression($callArg->value, $scope);
                if (!$resolved) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Unable to evaluate argument for "%s".', $constraint->name),
                        $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression,
                        ErrorCategory::TypeError,
                    );
                    return null;
                }

                if ($callArg->name === null) {
                    if ($namedSectionStarted) {
                        $errors[] = new ValidationError(
                            $path,
                            sprintf('Positional arguments must precede named arguments for "%s".', $constraint->name),
                            ErrorCode::UnknownArgumentName,
                            ErrorCategory::ValidationError,
                        );
                        return null;
                    }

                    if ($nextPositionalIndex >= count($validator->params)) {
                        $errors[] = new ValidationError(
                            $path,
                            sprintf('Too many arguments for validator "%s(%s)".', $validator->targetType, $validator->name),
                            ErrorCode::TooManyArguments,
                            ErrorCategory::ValidationError,
                        );
                        return null;
                    }

                    $paramName = $validator->params[$nextPositionalIndex]->name;
                    if (array_key_exists($paramName, $provided)) {
                        $errors[] = new ValidationError(
                            $path,
                            sprintf('Argument "%s" provided multiple times for "%s".', $paramName, $constraint->name),
                            ErrorCode::DuplicateArgument,
                            ErrorCategory::ValidationError,
                        );
                        return null;
                    }

                    $provided[$paramName] = $resolvedValue;
                    $nextPositionalIndex++;
                    continue;
                }

                $namedSectionStarted = true;
                if (!array_key_exists($callArg->name, $paramIndexByName)) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Unknown argument "%s" for validator "%s(%s)".', $callArg->name, $validator->targetType, $validator->name),
                        ErrorCode::UnknownArgumentName,
                        ErrorCategory::ValidationError,
                    );
                    return null;
                }

                if (array_key_exists($callArg->name, $provided)) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Argument "%s" provided multiple times for "%s".', $callArg->name, $constraint->name),
                        ErrorCode::DuplicateArgument,
                        ErrorCategory::ValidationError,
                    );
                    return null;
                }

                $provided[$callArg->name] = $resolvedValue;
            }
        } elseif ($constraint->arg !== null) {
            if ($constraint->arg instanceof SingleArgNode) {
                [$resolved, $single] = $this->evaluateExpression($constraint->arg->value, $scope);
                if (!$resolved) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Unable to evaluate argument for "%s".', $constraint->name),
                        $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression,
                        ErrorCategory::TypeError,
                    );
                    return null;
                }

                if ($validator->params === []) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Too many arguments for validator "%s(%s)".', $validator->targetType, $validator->name),
                        ErrorCode::TooManyArguments,
                        ErrorCategory::ValidationError,
                    );
                    return null;
                }

                $provided[$validator->params[0]->name] = $single;
            } elseif ($constraint->arg instanceof ListArgNode) {
                foreach ($constraint->arg->items as $index => $item) {
                    [$resolved, $itemValue] = $this->evaluateExpression($item, $scope);
                    if (!$resolved) {
                        $errors[] = new ValidationError(
                            $path,
                            sprintf('Unable to evaluate list argument for "%s".', $constraint->name),
                            $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression,
                            ErrorCategory::TypeError,
                        );
                        return null;
                    }

                    if ($index >= count($validator->params)) {
                        $errors[] = new ValidationError(
                            $path,
                            sprintf('Too many arguments for validator "%s(%s)".', $validator->targetType, $validator->name),
                            ErrorCode::TooManyArguments,
                            ErrorCategory::ValidationError,
                        );
                        return null;
                    }

                    $provided[$validator->params[$index]->name] = $itemValue;
                }
            }
        }

        $bound = [];
        foreach ($validator->params as $param) {
            $value = null;
            $hasValue = false;

            if (array_key_exists($param->name, $provided)) {
                $value = $provided[$param->name];
                $hasValue = true;
            } elseif ($param->default !== null) {
                $defaultScope = $scope->withVariables($this->augmentScopeVariables($scope->variables, $bound));
                [$resolvedDefault, $defaultValue] = $this->evaluateExpression($param->default, $defaultScope);
                if (!$resolvedDefault) {
                    $errors[] = new ValidationError(
                        $path,
                        sprintf('Unable to evaluate default for argument "%s".', $param->name),
                        $this->lastEvaluationErrorCode ?? ErrorCode::InvalidExpression,
                        ErrorCategory::TypeError,
                    );
                    return null;
                }

                $value = $defaultValue;
                $hasValue = true;
            }

            if (!$hasValue) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf(
                        'Missing argument "%s" for validator "%s(%s)".',
                        $param->name,
                        $validator->targetType,
                        $validator->name,
                    ),
                    ErrorCode::MissingArgument,
                    ErrorCategory::ValidationError,
                );
                return null;
            }

            if ($param->typeHint !== null && !$this->validatorArgumentMatchesTypeHint(
                value: $value,
                typeHint: $param->typeHint,
                scope: $scope,
                repository: $repository,
                typeStack: $typeStack,
            )) {
                $errors[] = new ValidationError(
                    $path,
                    sprintf(
                        'Argument "%s" does not match declared type hint "%s".',
                        $param->name,
                        $param->typeHint,
                    ),
                    ErrorCode::TypeMismatch,
                    ErrorCategory::TypeError,
                );
                return null;
            }

            $bound[$param->name] = $value;
        }

        return $bound;
    }

    /**
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $bound
     * @return array<string, mixed>
     */
    private function augmentScopeVariables(array $variables, array $bound): array
    {
        $merged = $variables;
        foreach ($bound as $name => $value) {
            $merged[$name] = $value;
            $merged['$' . $name] = $value;
        }

        return $merged;
    }

    /**
     * @param array<string, int> $typeStack
     */
    private function validatorArgumentMatchesTypeHint(
        mixed $value,
        string $typeHint,
        ValidationScope $scope,
        SchemaRepository $repository,
        array $typeStack,
    ): bool {
        $definition = $repository->getType($typeHint);
        if ($definition === null) {
            return true;
        }

        if ($definition instanceof BuiltinTypeDefinition) {
            return $definition->matches($value);
        }

        if (!$definition instanceof TypeDefinition) {
            return true;
        }

        $errors = [];

        return $this->validateType(
            value: $value,
            type: $definition->expr,
            path: '$',
            scope: $scope,
            repository: $repository,
            errors: $errors,
            typeStack: $typeStack,
        );
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     */
    private function validateRecordType(
        mixed $value,
        RecordTypeNode $type,
        string $path,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        if (!$this->isObjectLike($value)) {
            $errors[] = new ValidationError($path, 'Expected JSON object.');
            return false;
        }

        $recordScope = $scope->child($value);
        $valid = true;

        $declaredFields = [];
        foreach ($type->fields as $field) {
            $declaredFields[$field->name] = true;
            if (!$this->validateRecordField($value, $field, $path, $recordScope, $repository, $errors, $typeStack)) {
                $valid = false;
            }
        }

        foreach ($this->objectKeys($value) as $fieldName) {
            if (isset($declaredFields[$fieldName])) {
                continue;
            }

            $errors[] = new ValidationError(
                $path . '.' . $fieldName,
                sprintf('Unexpected field "%s".', $fieldName),
                ErrorCode::UnknownField,
                ErrorCategory::SemanticError,
            );
            $valid = false;
        }

        return $valid;
    }

    /**
     * @param ValidationError[] $errors
     * @param array<string, int> $typeStack
     */
    private function validateRecordField(
        mixed $recordValue,
        FieldNode $field,
        string $recordPath,
        ValidationScope $scope,
        SchemaRepository $repository,
        array &$errors,
        array $typeStack,
    ): bool {
        $fieldPath = $recordPath . '.' . $field->name;
        $exists = $this->objectHasKey($recordValue, $field->name);

        if (!$exists) {
            if ($field->optional || $field->default !== null) {
                return true;
            }

            if ($this->typeAllowsMissing($field->type, $scope, $repository, $typeStack)) {
                return true;
            }

            $errors[] = new ValidationError(
                $fieldPath,
                sprintf('Missing required field "%s".', $field->name),
                ErrorCode::FieldMissing,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        if ($field->type instanceof AbsentTypeNode) {
            $errors[] = new ValidationError(
                $fieldPath,
                'Field must be absent.',
                ErrorCode::FieldMustBeAbsent,
                ErrorCategory::ValidationError,
            );
            return false;
        }

        $fieldValue = $this->objectGet($recordValue, $field->name);

        return $this->validateType(
            value: $fieldValue,
            type: $field->type,
            path: $fieldPath,
            scope: $scope,
            repository: $repository,
            errors: $errors,
            typeStack: $typeStack,
        );
    }

    /**
     * @param array<string, int> $typeStack
     */
    private function typeAllowsMissing(
        TypeExprNode $type,
        ValidationScope $scope,
        SchemaRepository $repository,
        array $typeStack,
    ): bool {
        if ($type instanceof AbsentTypeNode) {
            return true;
        }

        if ($type instanceof UnionTypeNode) {
            foreach ($type->items as $item) {
                if ($this->typeAllowsMissing($item, $scope, $repository, $typeStack)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof IntersectionTypeNode) {
            foreach ($type->items as $item) {
                if (!$this->typeAllowsMissing($item, $scope, $repository, $typeStack)) {
                    return false;
                }
            }

            return true;
        }

        if ($type instanceof ConditionalTypeNode) {
            $condition = $this->evaluatePredicate($type->condition, $scope);

            if ($condition === true) {
                return $this->typeAllowsMissing($type->thenType, $scope, $repository, $typeStack);
            }

            if ($condition === false) {
                return $this->typeAllowsMissing($type->elseType, $scope, $repository, $typeStack);
            }

            return $this->typeAllowsMissing($type->thenType, $scope, $repository, $typeStack)
                || $this->typeAllowsMissing($type->elseType, $scope, $repository, $typeStack);
        }

        if ($type instanceof NamedTypeNode) {
            $definition = $repository->getType($type->name);
            if ($definition === null) {
                return false;
            }

            if ($definition instanceof BuiltinTypeDefinition) {
                return false;
            }

            if (!$definition instanceof TypeDefinition) {
                return false;
            }

            $nextDepth = ($typeStack[$type->name] ?? 0) + 1;
            if ($nextDepth > self::MAX_TYPE_RECURSION) {
                return false;
            }

            $typeStack[$type->name] = $nextDepth;

            return $this->typeAllowsMissing($definition->expr, $scope, $repository, $typeStack);
        }

        return false;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateConstraintArgument(ArgValueNode $arg, ValidationScope $scope): array
    {
        if ($arg instanceof SingleArgNode) {
            return $this->evaluateExpression($arg->value, $scope);
        }

        if ($arg instanceof ListArgNode) {
            $items = [];
            foreach ($arg->items as $item) {
                [$resolved, $value] = $this->evaluateExpression($item, $scope);
                if (!$resolved) {
                    return [false, null];
                }

                $items[] = $value;
            }

            return [true, $items];
        }

        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateExpression(ExpressionNode $expression, ValidationScope $scope): array
    {
        $this->lastEvaluationErrorCode = null;

        if ($expression instanceof UnaryArithmeticExprNode) {
            [$resolved, $operand] = $this->evaluateExpression($expression->operand, $scope);
            if (!$resolved) {
                return [false, null];
            }

            return $this->evaluateUnaryArithmetic($expression->operator, $operand);
        }

        if ($expression instanceof BinaryArithmeticExprNode) {
            [$leftResolved, $leftValue] = $this->evaluateExpression($expression->left, $scope);
            [$rightResolved, $rightValue] = $this->evaluateExpression($expression->right, $scope);
            if (!$leftResolved || !$rightResolved) {
                return [false, null];
            }

            return $this->evaluateBinaryArithmetic($expression->operator, $leftValue, $rightValue);
        }

        if ($expression instanceof LiteralNode) {
            return [true, $this->literalToPhpValue($expression)];
        }

        if ($expression instanceof PathNode) {
            return $this->resolvePath($expression, $scope);
        }

        if ($expression instanceof EmptyArrayExprNode) {
            return [true, []];
        }

        if ($expression instanceof FunctionCallExprNode) {
            if ($expression->name === 'now' && $expression->args === []) {
                return [true, (new DateTimeImmutable())->format('Y-m-d H:i:s')];
            }

            if ($expression->name === 'midnight' && $expression->args === []) {
                return [true, (new DateTimeImmutable('today midnight'))->format('Y-m-d H:i:s')];
            }

            if ($expression->name === 'pi' && $expression->args === []) {
                return [true, pi()];
            }

            return [false, null];
        }

        if ($expression instanceof ComparePredicateNode
            || $expression instanceof BinaryPredicateNode
            || $expression instanceof MatchesPredicateNode
            || $expression instanceof NotPredicateNode) {
            $predicate = $this->evaluatePredicate($expression, $scope);
            if ($predicate === null) {
                return [false, null];
            }

            return [true, $predicate];
        }

        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateUnaryArithmetic(UnaryArithmeticOperator $operator, mixed $operand): array
    {
        if (is_int($operand) || is_float($operand)) {
            $this->lastEvaluationErrorCode = null;
            return [true, $operator === UnaryArithmeticOperator::MINUS ? -$operand : +$operand];
        }

        $duration = $this->durationToMilliseconds($operand);
        if ($duration !== null) {
            $this->lastEvaluationErrorCode = null;
            return [true, $operator === UnaryArithmeticOperator::MINUS ? -$duration : $duration];
        }

        $this->lastEvaluationErrorCode = ErrorCode::InvalidArithmetic;
        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateBinaryArithmetic(ArithmeticOperator $operator, mixed $left, mixed $right): array
    {
        if ($operator === ArithmeticOperator::ADD || $operator === ArithmeticOperator::SUBTRACT) {
            $result = $this->evaluateTemporalArithmetic($operator, $left, $right);
            if ($result[0]) {
                return $result;
            }

            if ($this->lastEvaluationErrorCode !== ErrorCode::InvalidArithmetic) {
                return $result;
            }
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            if ($operator === ArithmeticOperator::DIVIDE && (float) $right === 0.0) {
                $this->lastEvaluationErrorCode = ErrorCode::InvalidArithmetic;
                return [false, null];
            }

            $this->lastEvaluationErrorCode = null;
            return [true, match ($operator) {
                ArithmeticOperator::ADD => $left + $right,
                ArithmeticOperator::SUBTRACT => $left - $right,
                ArithmeticOperator::MULTIPLY => $left * $right,
                ArithmeticOperator::DIVIDE => $left / $right,
            }];
        }

        $leftDuration = $this->durationToMilliseconds($left);
        $rightDuration = $this->durationToMilliseconds($right);
        $rightNumber = (is_int($right) || is_float($right)) ? $right : null;
        $leftNumber = (is_int($left) || is_float($left)) ? $left : null;

        if ($leftDuration !== null && $rightDuration !== null) {
            $this->lastEvaluationErrorCode = null;
            return match ($operator) {
                ArithmeticOperator::ADD => [true, $leftDuration + $rightDuration],
                ArithmeticOperator::SUBTRACT => [true, $leftDuration - $rightDuration],
                default => $this->invalidArithmeticResult(),
            };
        }

        if ($leftDuration !== null && $rightNumber !== null) {
            if ($operator === ArithmeticOperator::MULTIPLY) {
                $this->lastEvaluationErrorCode = null;
                return [true, (int) round($leftDuration * $rightNumber)];
            }

            if ($operator === ArithmeticOperator::DIVIDE) {
                if ((float) $rightNumber === 0.0) {
                    return $this->invalidArithmeticResult();
                }

                $this->lastEvaluationErrorCode = null;
                return [true, (int) round($leftDuration / $rightNumber)];
            }
        }

        if ($leftNumber !== null && $rightDuration !== null && $operator === ArithmeticOperator::MULTIPLY) {
            $this->lastEvaluationErrorCode = null;
            return [true, (int) round($leftNumber * $rightDuration)];
        }

        $this->lastEvaluationErrorCode = ErrorCode::InvalidArithmetic;
        return [false, null];
    }

    private function evaluatePredicate(ExpressionNode $expression, ValidationScope $scope): ?bool
    {
        if ($expression instanceof NotPredicateNode) {
            $inner = $this->evaluatePredicate($expression->inner, $scope);
            return $inner === null ? null : !$inner;
        }

        if ($expression instanceof BinaryPredicateNode) {
            $left = $this->evaluatePredicate($expression->left, $scope);
            $right = $this->evaluatePredicate($expression->right, $scope);

            if ($left === null || $right === null) {
                return null;
            }

            return match ($expression->operator) {
                BinaryPredicateOperator::AND => $left && $right,
                BinaryPredicateOperator::OR => $left || $right,
            };
        }

        if ($expression instanceof ComparePredicateNode) {
            [$leftResolved, $leftValue] = $this->evaluateExpression($expression->left, $scope);
            [$rightResolved, $rightValue] = $this->evaluateExpression($expression->right, $scope);
            if (!$leftResolved || !$rightResolved) {
                return null;
            }

            return $this->compareValues($leftValue, $rightValue, $expression->operator);
        }

        if ($expression instanceof MatchesPredicateNode) {
            [$resolved, $candidate] = $this->evaluateExpression($expression->expression, $scope);
            if (!$resolved || !is_string($candidate)) {
                return null;
            }

            return $this->matchesRegexPattern($candidate, $expression->regexPattern);
        }

        [$resolved, $value] = $this->evaluateExpression($expression, $scope);
        if (!$resolved) {
            return null;
        }

        return $this->toBoolean($value);
    }

    private function compareValues(mixed $left, mixed $right, CompareOperator $operator): ?bool
    {
        return match ($operator) {
            CompareOperator::EQUAL => $left === $right,
            CompareOperator::NOT_EQUAL => $left !== $right,
            CompareOperator::LESS => $this->compareOrderedValues($left, $right, static fn ($a, $b): bool => $a < $b),
            CompareOperator::LESS_OR_EQUAL => $this->compareOrderedValues($left, $right, static fn ($a, $b): bool => $a <= $b),
            CompareOperator::MORE => $this->compareOrderedValues($left, $right, static fn ($a, $b): bool => $a > $b),
            CompareOperator::MORE_OR_EQUAL => $this->compareOrderedValues($left, $right, static fn ($a, $b): bool => $a >= $b),
        };
    }

    private function compareOrderedValues(mixed $left, mixed $right, callable $comparator): ?bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $comparator($left, $right);
        }

        if (is_string($left) && is_string($right)) {
            $leftTime = strtotime($left);
            $rightTime = strtotime($right);

            if ($leftTime !== false && $rightTime !== false) {
                return $comparator($leftTime, $rightTime);
            }

            return $comparator($left, $right);
        }

        return null;
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function evaluateTemporalArithmetic(ArithmeticOperator $operator, mixed $left, mixed $right): array
    {
        $leftTemporal = $this->toTemporalValue($left);
        $rightTemporal = $this->toTemporalValue($right);
        $leftDuration = $this->durationToMilliseconds($left);
        $rightDuration = $this->durationToMilliseconds($right);

        if ($leftTemporal !== null && $rightDuration !== null) {
            if ($operator === ArithmeticOperator::ADD || $operator === ArithmeticOperator::SUBTRACT) {
                $deltaMilliseconds = $operator === ArithmeticOperator::ADD
                    ? $rightDuration
                    : -$rightDuration;

                $modified = $this->shiftDateTimeByMilliseconds($leftTemporal['value'], $deltaMilliseconds);
                if ($modified === null) {
                    return $this->invalidArithmeticResult();
                }

                if ($leftTemporal['kind'] === 'date') {
                    $this->lastEvaluationErrorCode = null;
                    return [true, $modified->format('Y-m-d')];
                }

                $this->lastEvaluationErrorCode = null;
                return [true, $modified->format('Y-m-d H:i:s')];
            }
        }

        if ($operator === ArithmeticOperator::SUBTRACT && $leftTemporal !== null && $rightTemporal !== null) {
            if ($leftTemporal['kind'] !== $rightTemporal['kind']) {
                return $this->invalidArithmeticResult();
            }

            $deltaMicroseconds = $this->dateTimeToMicroseconds($leftTemporal['value']) - $this->dateTimeToMicroseconds($rightTemporal['value']);
            $this->lastEvaluationErrorCode = null;
            return [true, (int) round($deltaMicroseconds / 1000)];
        }

        if ($leftDuration !== null && $rightDuration !== null) {
            $this->lastEvaluationErrorCode = null;
            return match ($operator) {
                ArithmeticOperator::ADD => [true, $leftDuration + $rightDuration],
                ArithmeticOperator::SUBTRACT => [true, $leftDuration - $rightDuration],
                default => $this->invalidArithmeticResult(),
            };
        }

        return $this->invalidArithmeticResult();
    }

    /**
     * @return array{kind: 'date'|'datetime', value: DateTimeImmutable}|null
     */
    private function toTemporalValue(mixed $value): ?array
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
            if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) {
                return ['kind' => 'date', 'value' => $date];
            }
        }

        try {
            $dateTime = new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return ['kind' => 'datetime', 'value' => $dateTime];
    }

    private function durationToMilliseconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        if (preg_match(
            '/^(-?\d+)\s*(ms|s|m|h|d|w|milliseconds?|seconds?|minutes?|hours?|days?|weeks?)$/i',
            trim($value),
            $matches,
        ) !== 1) {
            return null;
        }

        $amount = (int) $matches[1];
        $unit = strtolower($matches[2]);
        $multiplier = match ($unit) {
            'ms', 'millisecond', 'milliseconds' => 1,
            's', 'second', 'seconds' => 1000,
            'm', 'minute', 'minutes' => 60 * 1000,
            'h', 'hour', 'hours' => 60 * 60 * 1000,
            'd', 'day', 'days' => 24 * 60 * 60 * 1000,
            'w', 'week', 'weeks' => 7 * 24 * 60 * 60 * 1000,
            default => null,
        };

        if ($multiplier === null) {
            return null;
        }

        return $amount * $multiplier;
    }

    private function shiftDateTimeByMilliseconds(DateTimeImmutable $dateTime, int $milliseconds): ?DateTimeImmutable
    {
        $totalMicroseconds = $this->dateTimeToMicroseconds($dateTime) + ($milliseconds * 1000);
        $seconds = intdiv($totalMicroseconds, 1000000);
        $microseconds = $totalMicroseconds % 1000000;

        if ($microseconds < 0) {
            $microseconds += 1000000;
            $seconds--;
        }

        $updated = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $microseconds));
        if (!$updated instanceof DateTimeImmutable) {
            return null;
        }

        return $updated->setTimezone($dateTime->getTimezone());
    }

    private function dateTimeToMicroseconds(DateTimeImmutable $dateTime): int
    {
        return ((int) $dateTime->format('U')) * 1000000 + (int) $dateTime->format('u');
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function invalidArithmeticResult(): array
    {
        $this->lastEvaluationErrorCode = ErrorCode::InvalidArithmetic;
        return [false, null];
    }

    /**
     * @return array{0: bool, 1: mixed}
     */
    private function resolvePath(PathNode $path, ValidationScope $scope): array
    {
        if ($path->rootKind === PathRootKind::PARENT && $scope->parent === null) {
            $this->lastEvaluationErrorCode = ErrorCode::ParentUndefined;
            return [false, null];
        }

        $base = match ($path->rootKind) {
            PathRootKind::THIS => $scope->current,
            PathRootKind::PARENT => $scope->parent,
            PathRootKind::ROOT => $scope->root,
            PathRootKind::IDENTIFIER => $this->resolveIdentifierRoot($path, $scope->current),
            PathRootKind::VARIABLE => $this->resolveVariableRoot($path, $scope),
        };

        if ($base === null) {
            return [true, null];
        }

        foreach ($path->segments as $segment) {
            if (!$this->objectHasKey($base, $segment)) {
                return [true, null];
            }

            $base = $this->objectGet($base, $segment);
        }

        return [true, $base];
    }

    private function resolveIdentifierRoot(PathNode $path, mixed $scopeCurrent): mixed
    {
        if ($path->rootName === null) {
            return null;
        }

        if (!$this->objectHasKey($scopeCurrent, $path->rootName)) {
            return null;
        }

        return $this->objectGet($scopeCurrent, $path->rootName);
    }

    private function resolveVariableRoot(PathNode $path, ValidationScope $scope): mixed
    {
        if ($path->rootName === null) {
            return null;
        }

        if (array_key_exists($path->rootName, $scope->variables)) {
            return $scope->variables[$path->rootName];
        }

        $trimmed = ltrim($path->rootName, '$');
        if (array_key_exists($trimmed, $scope->variables)) {
            return $scope->variables[$trimmed];
        }

        return null;
    }

    private function literalToPhpValue(LiteralNode $literal): mixed
    {
        if ($literal instanceof StringLiteralNode) {
            return $literal->value;
        }

        if ($literal instanceof NumberLiteralNode) {
            return $literal->numericValue;
        }

        if ($literal instanceof DurationLiteralNode) {
            return $literal->milliseconds;
        }

        if ($literal instanceof BoolLiteralNode) {
            return $literal->value;
        }

        if ($literal instanceof NullLiteralNode) {
            return null;
        }

        return null;
    }

    private function isObjectLike(mixed $value): bool
    {
        if (is_object($value)) {
            return true;
        }

        return is_array($value) && !array_is_list($value);
    }

    /**
     * @return string[]
     */
    private function objectKeys(mixed $value): array
    {
        if (is_object($value)) {
            return array_keys(get_object_vars($value));
        }

        if (is_array($value) && !array_is_list($value)) {
            return array_keys($value);
        }

        return [];
    }

    private function objectHasKey(mixed $value, string $key): bool
    {
        if (is_object($value)) {
            $vars = get_object_vars($value);
            return array_key_exists($key, $vars);
        }

        if (is_array($value) && !array_is_list($value)) {
            return array_key_exists($key, $value);
        }

        return false;
    }

    private function objectGet(mixed $value, string $key): mixed
    {
        if (is_object($value)) {
            $vars = get_object_vars($value);
            return $vars[$key] ?? null;
        }

        if (is_array($value)) {
            return $value[$key] ?? null;
        }

        return null;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return $value !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . $value . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        if (is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        return (string) $value;
    }

    private function valueType(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'bool';
        }

        if (is_int($value)) {
            return 'int';
        }

        if (is_float($value)) {
            return 'float';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'object';
        }

        if (is_object($value)) {
            return 'object';
        }

        return gettype($value);
    }
}
