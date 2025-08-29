<?php

/* @phan-file-suppress PhanUnreferencedClass,PhanUnreferencedPublicProperty */
/* @phan-file-suppress PhanUnreferencedProtectedProperty,PhanUnreferencedPrivateProperty */

class Test017RedundantPropertyComment {
    public int $redundantInt;
    /** @var non-empty-string */
    protected string $nonEmptyString;
    public bool $redundantWithEmptyLines;
    /** @var int Description */
    private int $withInlineDescription;
    /** @var string This description spans
      multiple lines */
    public string $withMultilineDescription;
    /** @var string */
    private $withoutRealType;
    /** No type! */
    protected string $withoutPHPDocType;
}
