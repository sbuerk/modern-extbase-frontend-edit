<?php

declare(strict_types=1);

namespace SBUERK\ModernExtbaseFrontendEdit\Http;

use TYPO3\CMS\Extbase\Error\Error;
use TYPO3\CMS\Extbase\Error\Result;

/**
 * The wire format of the AJAX endpoints: one success envelope, one error
 * envelope, and the encoder both go through.
 *
 * The service is stateless and holds nothing. It exists as a class rather than
 * as a handful of private controller methods because the three rules it
 * implements are contract, not formatting, and a second endpoint controller —
 * the image upload is deliberately a change of its own — has to hold to them
 * unchanged:
 *
 * 1. **Every response carries a JSON body, including every failure.** A
 *    frontend error response is otherwise HTML from `ErrorController`, which a
 *    client that always parses JSON cannot read.
 * 2. **`code` is a TYPO3 style unix timestamp exception code**, so an error a
 *    user reports is greppable to one line of PHP. The repository's
 *    `checkExceptionCodes` gate keeps them unique.
 * 3. **Nothing that was refused is echoed back.** {@see error()} takes a
 *    message the caller wrote, never a value the request carried, and
 *    {@see validationErrors()} passes on the property *name* and the
 *    validator's own sentence — never the submitted value.
 *
 * `message` is written for a developer. Localised, user facing text is the
 * client's job for everything except a validation failure, where the sentence
 * comes from the rule set's XLIFF key and is already translated by
 * `AbstractValidator::translateErrorMessage()`.
 *
 * @see \SBUERK\ModernExtbaseFrontendEdit\Controller\ProfileAjaxController
 */
final readonly class JsonEnvelope
{
    /**
     * `JSON_THROW_ON_ERROR` turns an unencodable payload into a `\JsonException`
     * rather than into the string `false`, which would leave the endpoint
     * answering `200` with an empty body.
     *
     * `JSON_HEX_*` is deliberately not set. Those flags exist to make a JSON
     * document safe to embed into HTML, and nothing here is embedded: the
     * response is served as `application/json` and the client reads it with
     * `Response.json()`.
     */
    private const ENCODING_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * The success envelope.
     *
     * `data` is the persisted state as the server sees it *after* the write,
     * not an echo of the request — a client that trusts its own optimistic
     * update drifts away from the database on the first rule that normalises a
     * value.
     *
     * @param array<string, mixed> $data
     */
    public function data(array $data): string
    {
        return $this->encode(['data' => $data]);
    }

    /**
     * The failure envelope without field context, used for `400`, `403`, `404`,
     * `405` and `409`.
     *
     * It is one entry rather than a bare object so that a client parses one
     * shape for every failure, and so that a future multi-error case does not
     * change the contract.
     */
    public function error(int $code, string $message): string
    {
        return $this->encode(['errors' => [['code' => $code, 'message' => $message]]]);
    }

    /**
     * The failure envelope of a `422`, one entry per rejected property.
     *
     * The keys of `Result::getFlattenedErrors()` are property paths — `''` for
     * an error attached to the object itself, `firstname` for a property, and a
     * dotted path for a nested one. The empty path is mapped to `null`, because
     * `"field": ""` reads as "a field with an empty name" on the client side
     * while `null` reads as "no field".
     *
     * `getMessage()` rather than `render()`: the validators of this extension
     * and of the core alike hand `addError()` an already translated *and*
     * already substituted sentence, and pass the arguments a second time only
     * so that a consumer can re-render it. Calling `render()` would run
     * `vsprintf()` over a string whose placeholders are gone.
     */
    public function validationErrors(Result $result): string
    {
        $errors = [];
        foreach ($result->getFlattenedErrors() as $propertyPath => $propertyErrors) {
            foreach ($propertyErrors as $error) {
                if (!$error instanceof Error) {
                    continue;
                }
                $errors[] = [
                    'field' => $propertyPath === '' ? null : $propertyPath,
                    'code' => $error->getCode(),
                    'message' => $error->getMessage(),
                ];
            }
        }

        return $this->encode(['errors' => $errors]);
    }

    /**
     * @param array<string, mixed> $payload
     * @throws \JsonException
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODING_FLAGS);
    }
}
