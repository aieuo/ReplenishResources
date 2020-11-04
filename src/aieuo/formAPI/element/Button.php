<?php

namespace aieuo\formAPI\element;

class Button extends Element {

    /** @var callable|null */
    private $onClick;

    public function __construct(string $text, callable $onClick = null, ?string $uuid = null) {
        parent::__construct($text, $uuid);
        $this->onClick = $onClick;
    }

    public function getOnClick(): ?callable {
        return $this->onClick;
    }

    public function jsonSerialize(): array {
        return ["text" => str_replace("\\n", "\n", $this->text), "id" => $this->getUUId(),];
    }
}