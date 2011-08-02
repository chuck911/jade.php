<?php

class Parser {

    protected $lexer;

    /**
     * Initialize Parser.
     *
     * @param   Lexer $lexer  lexer object
     */
    public function __construct(Lexer $lexer) {
        $this->lexer = $lexer;
    }

    /**
     * Parse input returning block node.
     *
     * @param   string          $input  jade document
     *
     * @return  BlockNode
     */
    public function parse($input) {
        $this->lexer->setInput($input);

        $node = new BlockNode($this->lexer->getCurrentLine());

        while ( $this->lexer->predictToken()->type !== 'eos' ) {
            if ( $this->lexer->predictToken()->type === 'newline' ) {
                $this->lexer->getAdvancedToken();
            } else {
                $node->addChild($this->parseExpression());
            }
        }

        return $node;
    }

    /**
     * Expect given type or throw Exception.
     *
     * @param   string  $type   type
     */
    protected function expectTokenType($type) {
        if ( $this->lexer->predictToken()->type === $type ) {
            return $this->lexer->getAdvancedToken();
        } else {
            throw new \Exception(sprintf('Expected %s, but got %s', $type, $this->lexer->predictToken()->type));
        }
    }

    /**
     * Accept given type.
     *
     * @param   string  $type   type
     */
    protected function acceptTokenType($type) {
        if ( $this->lexer->predictToken()->type === $type ) {
            return $this->lexer->getAdvancedToken();
        }
    }

    /**
     * Parse current expression & return Node.
     *
     * @return  Node
     */
    protected function parseExpression() {
        switch ( $this->lexer->predictToken()->type ) {
            case 'tag':
                return $this->parseTag();
            case 'doctype':
                return $this->parseDoctype();
            case 'filter':
                return $this->parseFilter();
            case 'comment':
                return $this->parseComment();
            case 'text':
                return $this->parseText();
            case 'code':
                return $this->parseCode();
            case 'id':
            case 'class':
                $token = $this->lexer->getAdvancedToken();
                $this->lexer->deferToken($this->lexer->takeToken('tag', 'div'));
                $this->lexer->deferToken($token);

                return $this->parseExpression();
        }
    }

    /**
     * Parse next text token.
     *
     * @return  TextNode
     */
    protected function parseText($trim = false) {
        $token = $this->expectTokenType('text');
        $value = $trim ? preg_replace('/^ +/', '', $token->value) : $token->value;

        return new TextNode($value, $this->lexer->getCurrentLine());
    }

    /**
     * Parse next code token.
     *
     * @return  CodeNode
     */
    protected function parseCode() {
        $token  = $this->expectTokenType('code');
        $node   = new CodeNode($token->value, $token->buffer, $this->lexer->getCurrentLine());
		$node->setCodeType($token->code_type);

        // Skip newlines
        while ( $this->lexer->predictToken()->type === 'newline' ) {
            $this->lexer->getAdvancedToken();
        }

        if ( $this->lexer->predictToken()->type === 'indent' ) {
            $node->setBlock($this->parseBlock());
        }

        return $node;
    }

    /**
     * Parse next commend token.
     *
     * @return  CommentNode
     */
    protected function parseComment() {
        $token  = $this->expectTokenType('comment');
        $node   = new CommentNode(preg_replace('/^ +| +$/', '', $token->value), $token->buffer, $this->lexer->getCurrentLine());

        // Skip newlines
        while ( $this->lexer->predictToken()->type === 'newline' ) {
            $this->lexer->getAdvancedToken();
        }

        if ( $this->lexer->predictToken()->type === 'indent' ) {
            $node->setBlock($this->parseBlock());
        }

        return $node;
    }

    /**
     * Parse next doctype token.
     *
     * @return  DoctypeNode
     */
    protected function parseDoctype() {
        $token = $this->expectTokenType('doctype');

        return new DoctypeNode($token->value, $this->lexer->getCurrentLine());
    }

    /**
     * Parse next filter token.
     *
     * @return  FilterNode
     */
    protected function parseFilter() {
        $block      = null;
        $token      = $this->expectTokenType('filter');
        $attributes = $this->acceptTokenType('attributes');

        if ( $this->lexer->predictToken(2)->type === 'text' ) {
            $block = $this->parseTextBlock();
        } else {
            $block = $this->parseBlock();
        }

        $node = new FilterNode(
            $token->value, null !== $attributes ? $attributes->attributes : array(), $this->lexer->getCurrentLine()
        );
        $node->setBlock($block);

        return $node;
    }

    /**
     * Parse next indented? text token.
     *
     * @return  TextToken
     */
    protected function parseTextBlock() {
        $node = new TextNode(null, $this->lexer->getCurrentLine());

        $this->expectTokenType('indent');
        while ( $this->lexer->predictToken()->type === 'text' || $this->lexer->predictToken()->type === 'newline' ) {
            if ( $this->lexer->predictToken()->type === 'newline' ) {
                $this->lexer->getAdvancedToken();
            } else {
                $node->addLine($this->lexer->getAdvancedToken()->value);
            }
        }
        $this->expectTokenType('outdent');

        return $node;
    }

    /**
     * Parse indented block token.
     *
     * @return  BlockNode
     */
    protected function parseBlock() {
        $node = new BlockNode($this->lexer->getCurrentLine());

        $this->expectTokenType('indent');
        while ( $this->lexer->predictToken()->type !== 'outdent' ) {
            if ( $this->lexer->predictToken()->type === 'newline' ) {
                $this->lexer->getAdvancedToken();
            } else {
                $node->addChild($this->parseExpression());
            }
        }
        $this->expectTokenType('outdent');

        return $node;
    }

    /**
     * Parse tag token.
     *
     * @return  TagNode
     */
    protected function parseTag() {
        $name = $this->lexer->getAdvancedToken()->value;
        $node = new TagNode($name, $this->lexer->getCurrentLine());

        // Parse id, class, attributes token
        while ( true ) {
            switch ( $this->lexer->predictToken()->type ) {
                case 'id':
                case 'class':
                    $token = $this->lexer->getAdvancedToken();
                    $node->setAttribute($token->type, $token->value);
                    continue;
                case 'attributes':
                    foreach ( $this->lexer->getAdvancedToken()->attributes as $name => $value ) {
                        $node->setAttribute($name, $value);
                    }
                    continue;
                default:
                    break(2);
            }
        }

        // Parse text/code token
        switch ( $this->lexer->predictToken()->type ) {
            case 'text':
                $node->setText($this->parseText(true));
                break;
            case 'code':
                $node->setCode($this->parseCode());
                break;
        }

        // Skip newlines
        while ( $this->lexer->predictToken()->type === 'newline' ) {
            $this->lexer->getAdvancedToken();
        }

        // Tag text on newline
        if ( $this->lexer->predictToken()->type === 'text' ) {
            if ($text = $node->getText()) {
                $text->addLine('');
            } else {
                $node->setText(new TextNode('', $this->lexer->getCurrentLine()));
            }
        }

        // Parse block indentation
        if ( $this->lexer->predictToken()->type === 'indent' ) {
            $node->addChild($this->parseBlock());
        }

        return $node;
    }
}
?>
