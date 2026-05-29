<?php
namespace app\exception;

// Base application exception. Subclasses live in their own files (PSR-4) so the
// autoloader can resolve them when thrown directly — see ForbiddenException,
// NotFoundException, ConflictException, AuthException, ActivityStateException,
// OrderStateException in this namespace.
class AppException extends \RuntimeException {}
