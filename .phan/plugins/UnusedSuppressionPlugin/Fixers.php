<?php

declare(strict_types=1);

namespace UnusedSuppressionPlugin;

use Microsoft\PhpParser;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression;
use Microsoft\PhpParser\Node\MethodDeclaration;
use Microsoft\PhpParser\Node\PropertyDeclaration;
use Microsoft\PhpParser\Node\Statement\FunctionDeclaration;
use Microsoft\PhpParser\ParseContext;
use Microsoft\PhpParser\PhpTokenizer;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phan\CodeBase;
use Phan\IssueInstance;
use Phan\Language\Element\Comment\Builder;
use Phan\Library\FileCacheEntry;
use Phan\Plugin\Internal\BuiltinSuppressionPlugin;
use Phan\Plugin\Internal\IssueFixingPlugin\FileEdit;
use Phan\Plugin\Internal\IssueFixingPlugin\FileEditSet;

/**
 * This plugin implements --automatic-fix for UnusedSuppressionPlugin
 */
class Fixers
{
    /**
     * Remove an unused suppression
     * @param CodeBase $code_base @unused-param
     */
    public static function fixUnusedSuppression(
        CodeBase $code_base,
        FileCacheEntry $contents,
        IssueInstance $instance
    ): ?FileEditSet {
        $line_number = $instance->getLine();
        $line_content = $contents->getLine($line_number);
        if ($line_content === null) {
            // Impossible?!
            return null;
        }

        $suppressed_issue_name = $instance->getTemplateParameters()[1];
        $line_nodes = $contents->getNodesAtLine($instance->getLine());
        if ($line_nodes) {
            // Could be a block-level suppression, or a single-line comment in the same line as other statements.
            // We only handle the first case for now.
            return self::getBlockLevelSuppressionFix($suppressed_issue_name, $line_nodes, $line_number, $contents);
        }
        if (preg_match('!\s+//!',$line_content)) {
            // This line contains only a single-line comment, probably where the suppression is from.
            return self::getInlineSuppressionFix($suppressed_issue_name, $line_content, $line_number, $contents);
        }
        // Impossible?
        return null;
    }

    /**
     * Handles suppressions in single-line comments, when there's nothing but the comment on that line.
     */
    private static function getInlineSuppressionFix(
        string $suppressed_issue_name,
        string $line_content,
        int $line_number,
        FileCacheEntry $contents
    ): ?FileEditSet {
        $suppressions = BuiltinSuppressionPlugin::yieldSuppressionCommentsFromTokenContents($line_content, $line_number);
        if (count($suppressions) !== 1) {
            // Probably possible in theory, but skip for now.
            return null;
        }
        $suppression = $suppressions[0];
        $suppression_type = $suppression[3];
        if ($suppression_type === 'suppress-next-next-line' || $suppression_type === 'suppress-previous-line') {
            $next_line_content = $contents->getLine($line_number + 1);
            if ($next_line_content && preg_match('!\s+//!', $next_line_content)) {
                // Skip, since there could be a multi-line explanation of the suppression and we don't
                // want to remove only a part of it.
                return null;
            }
        }

        $kind_replace_start_and_end = self::getReplacementStartAndEndInKindList($suppressed_issue_name, $suppression[4]);
        if ($kind_replace_start_and_end === null) {
            return null;
        }
        $kind_list_start_offset = $suppression[5];
        $edit = new FileEdit(
           $contents->getLineOffset($line_number) + $kind_list_start_offset + $kind_replace_start_and_end[0],
            $contents->getLineOffset($line_number) + $kind_list_start_offset + $kind_replace_start_and_end[1],
        ''
        );
        // XXX: Here we would check if the kind list is now empty, and remove the whole comment if that's the case.
        return new FileEditSet( [ $edit ] );
    }

    /**
     * @param list<PhpParser\Node> $nodes
     */
    private static function getBlockLevelSuppressionFix(
        string $suppressed_issue_name,
        array $nodes,
        int $line_number,
        FileCacheEntry $contents
    ): ?FileEditSet {
        $doc_block = self::getRelevantDocBlock($nodes);
        if (!$doc_block) {
            return null;
        }

        $edits = array_merge(
            self::getBlockEditsForNativeSuppression($doc_block, $suppressed_issue_name, $contents),
            self::getBlockEditsForPluginSuppression($doc_block, $suppressed_issue_name, $line_number, $contents)
        );
        // XXX: Here we would check if the doc block is now empty, and remove it if that's the case.
        return $edits ? new FileEditSet($edits) : null;
    }

    /**
     * @return FileEdit[]
     */
    private static function getBlockEditsForNativeSuppression(
        Token $doc_block,
        string $suppressed_issue_name,
        FileCacheEntry $contents
    ): array
    {
        $doc_block_text = $doc_block->getText($contents->getContents());
        $suppression_matches = [];
        $match_count = preg_match_all(Builder::PHAN_SUPPRESS_REGEX, $doc_block_text, $suppression_matches, PREG_OFFSET_CAPTURE);
        if (!$match_count) {
            return [];
        }

        $edits = [];
        for ($i = 0; $i < $match_count; $i++) {
            [ $kind_list_text, $kind_list_offset ] = $suppression_matches[1][$i];

            $kind_replace_start_and_end = self::getReplacementStartAndEndInKindList($suppressed_issue_name, $kind_list_text);
            if ($kind_replace_start_and_end === null) {
                continue;
            }
            $edits[] = new FileEdit(
                $doc_block->getStartPosition() + $kind_list_offset + $kind_replace_start_and_end[0],
                $doc_block->getStartPosition() + $kind_list_offset + $kind_replace_start_and_end[1],
                ''
            );
        }
        // XXX: Here we would check if the kind list is now empty, and remove the whole comment if that's the case.
        return $edits;
    }

    /**
     * @return FileEdit[]
     */
    private static function getBlockEditsForPluginSuppression(
        Token $doc_block,
        string $suppressed_issue_name,
        int $line_number,
        FileCacheEntry $contents
    ): array {
        $suppressions = BuiltinSuppressionPlugin::yieldSuppressionCommentsFromTokenContents(
            $doc_block->getText($contents->getContents()),
            $line_number
        );
        $edits = [];
        foreach ($suppressions as $suppression) {
            $next_line_content = $contents->getLine($line_number + 1);
            if ($next_line_content && preg_match('!\s*\*\s*[^\s/@]!', $next_line_content)) {
                // Next line is not comment end or an @-line. Could be an explanation of the suppression, so skip.
                continue;
            }

            $kind_replace_start_and_end = self::getReplacementStartAndEndInKindList($suppressed_issue_name, $suppression[4]);
            if ($kind_replace_start_and_end === null) {
                continue;
            }
            $kind_list_start_offset = $suppression[5];
            $edits[] = new FileEdit(
                $doc_block->getStartPosition() + $kind_list_start_offset + $kind_replace_start_and_end[0],
                $doc_block->getStartPosition() + $kind_list_start_offset + $kind_replace_start_and_end[0],
                ''
            );
        }
        // XXX: Here we would check if the kind list is now empty, and remove the whole comment if that's the case.
        return $edits;
    }


    /**
     * @return array{0:int,1:int}|null
     */
    private static function getReplacementStartAndEndInKindList(string $suppressed_issue_name, string $kind_list_text): ?array
    {
        $kind_list_replace_start = strpos($kind_list_text, $suppressed_issue_name);
        if ($kind_list_replace_start === false) {
            return null;
        }
        $kind_list_replace_end = $kind_list_replace_start + strlen($suppressed_issue_name);
        if (($kind_list_text[$kind_list_replace_end] ?? '') === ',') {
            // Remove trailing comma if there are more issues being suppressed here.
            ++$kind_list_replace_end;
        }
        return [ $kind_list_replace_start, $kind_list_replace_end ];
    }

    /**
     * @param list<PhpParser\Node> $nodes
     * @return Token|null
     */
    private static function getRelevantDocBlock(array $nodes): ?Token
    {
        foreach ($nodes as $node) {
            if (
                $node instanceof FunctionDeclaration || $node instanceof MethodDeclaration ||
                $node instanceof AnonymousFunctionCreationExpression ||
                $node instanceof PropertyDeclaration || $node instanceof ClassDeclaration
            ) {
                return self::getDocCommentToken($node);
            }
        }
        return null;
    }

    /**
     * Copied from PHPDocRedundantPlugin\Fixers et al., adapted from Node::getDocCommentText().
     * @suppress PhanThrowTypeAbsentForCall
     * @suppress PhanUndeclaredClassMethod
     * @suppress UnusedSuppression false positive for PhpTokenizer with polyfill due to https://github.com/Microsoft/tolerant-php-parser/issues/292
     */
    private static function getDocCommentToken(PhpParser\Node $node): ?Token
    {
        $leadingTriviaText = $node->getLeadingCommentAndWhitespaceText();
        $leadingTriviaTokens = PhpTokenizer::getTokensArrayFromContent(
            $leadingTriviaText,
            ParseContext::SourceElements,
            $node->getFullStartPosition(),
            false
        );
        for ($i = \count($leadingTriviaTokens) - 1; $i >= 0; $i--) {
            $token = $leadingTriviaTokens[$i];
            if ($token->kind === TokenKind::DocCommentToken) {
                return $token;
            }
        }
        return null;
    }
}
