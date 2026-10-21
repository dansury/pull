# PHP hosting compatibility

The existing standalone updater and its terminal interface remain PHP 7.4+
compatible. A minimal bootstrap is parsed separately from the engine, so an older
PHP handler reports the required version and the detected PHP/server versions
before evaluating newer syntax. PHP 8.1 and 8.3 are supported; PHP 8.5 is not a
requirement. PHP must be enabled by the host: a disabled handler cannot execute
any PHP-side fallback. Apache 2.4+ is relevant to .htaccess protections; nginx
requires equivalent rules. The engine and user settings remain unchanged.

Validation: lint and library-only engine load under PHP 8.3. Preserve all prior
deployment, credential, archive and rollback behavior.
