<?php

namespace aieuo\formAPI\element;

class Button extends Element {
    public function jsonSerialize(): array {
        return [
            "text" => str_replace("\\n", "\n", $this->text),
            "id" => $this->getUUId(),
        ];
    }
}