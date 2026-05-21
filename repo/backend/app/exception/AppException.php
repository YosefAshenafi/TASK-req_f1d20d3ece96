<?php
namespace app\exception;
class AppException extends \RuntimeException {}
class AuthException extends AppException {}
class ForbiddenException extends AppException {}
class NotFoundException extends AppException {}
class ConflictException extends AppException {}
class ActivityStateException extends AppException {}
class OrderStateException extends AppException {}
