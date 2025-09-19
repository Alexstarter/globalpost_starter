# GlobalPost error handling

The module normalises responses from the GlobalPost API before surfacing them to administrators. Failures are mapped to human-readable error codes and messages, while the storefront hides GlobalPost carriers whenever tariff calculation or shipment creation fails.

## Error code mapping

The table below summarises the API status codes and explicit error identifiers that are recognised by the module. The mapping is implemented in `GlobalPostShipping\Error\ErrorMapper`.

| HTTP status | Admin code                | Description                                                |
|-------------|---------------------------|------------------------------------------------------------|
| 400         | `api_bad_request`         | Request rejected by GlobalPost as invalid.                 |
| 401         | `api_auth_failed`         | Authentication failed, token must be updated.              |
| 403         | `api_forbidden`           | API access denied for the current credentials.             |
| 404         | `api_not_found`           | Requested shipment or document is missing.                 |
| 409         | `api_conflict`            | Duplicate shipment detected on GlobalPost.                 |
| 422         | `api_validation_failed`   | Payload validation failed on the GlobalPost side.          |
| 429         | `api_rate_limited`        | Too many requests sent to GlobalPost.                      |
| 5xx         | `api_service_unavailable` | GlobalPost service temporarily unavailable.                |

| API error code      | Admin code                |
|---------------------|---------------------------|
| `INVALID_TOKEN`     | `api_auth_failed`         |
| `AUTH_ERROR`        | `api_auth_failed`         |
| `PERMISSION_DENIED` | `api_forbidden`           |
| `NOT_FOUND`         | `api_not_found`           |
| `VALIDATION_ERROR`  | `api_validation_failed`   |
| `RATE_LIMITED`      | `api_rate_limited`        |
| `SERVER_ERROR`      | `api_service_unavailable` |

Other transport failures (timeouts, DNS issues, TLS problems) are grouped under the `api_transport` code.

## Debug logging

Administrators can enable the **Enable debug logging** switch in the module settings to log sanitised request metadata and retry attempts to the PrestaShop log. Sensitive data (names, addresses, phone numbers, tokens) are automatically masked before logging. Disable the switch once troubleshooting is complete to avoid verbose logs.

## Sample payload log

See [`docs/logs/sample_auto_shipment_log.json`](logs/sample_auto_shipment_log.json) for a masked example of the stored request/response audit trail.
