<?php

namespace aieuo\formAPI\element;

class CancelToggle extends Toggle {

    public function __construct(string $text = "<キャンセルして戻る>", bool $default = false) {
        parent::__construct($text, $default);
    }
}