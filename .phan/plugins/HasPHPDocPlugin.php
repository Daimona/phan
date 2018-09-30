<?php declare(strict_types=1);

use Phan\CodeBase;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\MarkupDescription;
use Phan\Language\Element\Property;
use Phan\PluginV2;
use Phan\PluginV2\AnalyzeClassCapability;
use Phan\PluginV2\AnalyzeFunctionCapability;
use Phan\PluginV2\AnalyzePropertyCapability;

/**
 * This file checks if an element (class or property) has a PHPDoc comment,
 * and that Phan can extract a plaintext summary/description from that comment.
 *
 * (e.g. for generating a hover description in the language server)
 *
 * It hooks into these events:
 *
 * - analyzeClass
 *   Once all classes are parsed, this method will be called
 *   on every method in the code base
 *
 * - analyzeProperty
 *   Once all functions have been parsed, this method will
 *   be called on every property in the code base.
 *
 * A plugin file must
 *
 * - Contain a class that inherits from \Phan\PluginV2
 *
 * - End by returning an instance of that class.
 *
 * It is assumed without being checked that plugins aren't
 * mangling state within the passed code base or context.
 *
 * Note: When adding new plugins,
 * add them to the corresponding section of README.md
 */
final class HasPHPDocPlugin extends PluginV2 implements
    AnalyzeClassCapability,
    AnalyzeFunctionCapability,
    AnalyzePropertyCapability
{
    /**
     * @param CodeBase $code_base
     * The code base in which the class exists
     *
     * @param Clazz $class
     * A class being analyzed
     *
     * @return void
     *
     * @override
     */
    public function analyzeClass(
        CodeBase $code_base,
        Clazz $class
    ) {
        $doc_comment = $class->getDocComment();
        if (!$doc_comment) {
            $this->emitIssue(
                $code_base,
                $class->getContext(),
                'PhanPluginNoCommentOnClass',
                'Class {CLASS} has no doc comment',
                [$class->getFQSEN()]
            );
            return;
        }
        // @phan-suppress-next-line PhanAccessMethodInternal
        $description = MarkupDescription::extractDescriptionFromDocComment($class);
        if (!$description) {
            $this->emitIssue(
                $code_base,
                $class->getContext(),
                'PhanPluginDescriptionlessCommentOnClass',
                'Class {CLASS} has no readable description: {STRING_LITERAL}',
                [$class->getFQSEN(), json_encode($class->getDocComment(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
            return;
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the property exists
     *
     * @param Property $property
     * A property being analyzed
     *
     * @return void
     *
     * @override
     */
    public function analyzeProperty(
        CodeBase $code_base,
        Property $property
    ) {
        if ($property->isDynamicProperty()) {
            // And dynamic properties don't have phpdoc.
        }
        if ($property->isFromPHPDoc()) {
            // Phan does not track descriptions of (at)property.
            return;
        }
        if ($property->getFQSEN() !== $property->getRealDefiningFQSEN()) {
            // Only warn once for the original definition of this property.
            // Don't warn about subclasses inheriting this property.
            return;
        }
        $doc_comment = $property->getDocComment();
        if (!$doc_comment) {
            $visibility_upper = ucfirst($property->getVisibilityName());
            $this->emitIssue(
                $code_base,
                $property->getContext(),
                "PhanPluginNoCommentOn${visibility_upper}Property",
                "$visibility_upper property {PROPERTY} has no doc comment",
                [$property->getFQSEN()]
            );
            return;
        }
        // @phan-suppress-next-line PhanAccessMethodInternal
        $description = MarkupDescription::extractDescriptionFromDocComment($property);
        if (!$description) {
            $visibility_upper = ucfirst($property->getVisibilityName());
            $this->emitIssue(
                $code_base,
                $property->getContext(),
                "PhanPluginDescriptionlessCommentOn${visibility_upper}Property",
                "$visibility_upper property {PROPERTY} has no readable description: {STRING_LITERAL}",
                [$property->getFQSEN(), json_encode($property->getDocComment(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
            return;
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the function exists
     *
     * @param Func $function
     * A function being analyzed
     *
     * @return void
     *
     * @override
     */
    public function analyzeFunction(
        CodeBase $code_base,
        Func $function
    ) {
        $doc_comment = $function->getDocComment();
        if ($function->isPHPInternal()) {
            // This isn't user-defined, there's no reason to warn or way to change it.
            return;
        }
        if ($function->isClosure()) {
            // Probably not useful in many cases to document a short closure passed to array_map, etc.
            return;
        }
        if (!$doc_comment) {
            $this->emitIssue(
                $code_base,
                $function->getContext(),
                "PhanPluginNoCommentOnFunction",
                "Function {FUNCTION} has no doc comment",
                [$function->getFQSEN()]
            );
            return;
        }
        // @phan-suppress-next-line PhanAccessMethodInternal
        $description = MarkupDescription::extractDescriptionFromDocComment($function);
        if (!$description) {
            $this->emitIssue(
                $code_base,
                $function->getContext(),
                "PhanPluginDescriptionlessCommentOnFunction",
                "Function {FUNCTION} has no readable description: {STRING_LITERAL}",
                [$function->getFQSEN(), json_encode($function->getDocComment(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]
            );
            return;
        }
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new HasPHPDocPlugin();
