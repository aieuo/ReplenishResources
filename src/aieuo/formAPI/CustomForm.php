<?php

namespace aieuo\formAPI;

use aieuo\formAPI\element\CancelToggle;
use aieuo\formAPI\element\Dropdown;
use aieuo\formAPI\element\Element;
use aieuo\formAPI\element\Input;
use aieuo\formAPI\element\NumberInput;
use aieuo\formAPI\element\Slider;
use aieuo\formAPI\element\Toggle;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class CustomForm extends Form {

    protected $type = self::CUSTOM_FORM;

    /** @var Element[] */
    private $contents = [];

    /**
     * @param array $contents
     * @return self
     */
    public function setContents(array $contents): self {
        $this->contents = $contents;
        return $this;
    }

    /**
     * @param Element $content
     * @param bool $add
     * @return self
     */
    public function addContent(Element $content, bool $add = true): self {
        if ($add) $this->contents[] = $content;
        return $this;
    }

    /**
     * @return Element[]
     */
    public function getContents(): array {
        return $this->contents;
    }

    public function getContent(int $index): ?Element {
        return $this->contents[$index] ?? null;
    }

    public function addContents(Element ...$contents): self {
        $this->contents = array_merge($this->contents, $contents);
        return $this;
    }

    public function setContent(Element $element, int $index): self {
        $this->contents[$index] = $element;
        return $this;
    }

    public function jsonSerialize(): array {
        $form = [
            "type" => "custom_form",
            "title" => $this->title,
            "content" => $this->contents
        ];
        $form = $this->reflectErrors($form);
        return $form;
    }

    public function resetErrors(): Form {
        foreach ($this->getContents() as $content) {
            $content->setHighlight(null);
            $content->setExtraText("");
        }
        return parent::resetErrors();
    }

    public function reflectErrors(array $form): array {
        for ($i = 0, $iMax = count($form["content"]); $i < $iMax; $i++) {
            if (empty($this->highlights[$i])) continue;
            /** @var Element $content */
            $content = $form["content"][$i];
            $content->setHighlight(TextFormat::YELLOW);
        }
        if (!empty($this->messages) and !empty($this->contents)) {
            $form["content"][0]->setExtraText(implode("\n", array_keys($this->messages))."\n");
        }
        return $form;
    }

    public function resend(array $errors = [], array $messages = [], array $overwrites = []): void {
        if (empty($this->lastResponse) or !($this->lastResponse[0] instanceof Player) or !$this->lastResponse[0]->isOnline()) return;

        $this->setDefaultsFromResponse($this->lastResponse[1], $overwrites)
            ->resetErrors()
            ->addMessages($messages)
            ->addErrors($errors)
            ->show($this->lastResponse[0]);
    }

    public function handleResponse(Player $player, $data): void {
        $this->lastResponse = [$player, $data];
        if ($data !== null) {
            $errors = [];
            $isCanceled = false;
            $resend = false;
            $overwrites = [];
            foreach ($this->getContents() as $i => $content) {
                if ($content instanceof Input) {
                    $data[$i] = str_replace("\\n", "\n", $data[$i]);

                    if ($content->isRequired() and $data[$i] === "") {
                        $errors[] = ["必要事項を入力してください", $i];
                        continue;
                    }

                    if ($content instanceof NumberInput) {
                        if ($data[$i] === "") continue;

                        if (!is_numeric($data[$i])) {
                            $errors[] = ["§c値は半角数字を入力してください§f", $i];
                        } elseif (($min = $content->getMin()) !== null and (float)$data[$i] < $min) {
                            $errors[] = ["§c値は{$min}以上にしてください§f", $i];
                        } elseif (($max = $content->getMax()) !== null and (float)$data[$i] > $max) {
                            $errors[] = ["§c値は{$max}以下にしてください§f", $i];
                        } elseif (($excludes = $content->getExcludes()) !== null and in_array((float)$data[$i], $excludes, true)) {
                            $errors[] = ["§c値は[".implode(",", $excludes)."]以外にしてください§f", $i];
                        }
                    }
                } elseif ($content instanceof CancelToggle and $data[$i]) {
                    $isCanceled = true;
                }
            }

            if ($resend or (!$isCanceled and !empty($errors))) {
                $this->resend($errors, [], $overwrites);
                return;
            }
        }

        parent::handleResponse($player, $data);
    }

    private function setDefaultsFromResponse(array $data, array $overwrites): self {
        foreach ($this->getContents() as $i => $content) {
            if ($content instanceof Input or $content instanceof Slider or $content instanceof Dropdown or $content instanceof Toggle) {
                $content->setDefault($overwrites[$i] ?? $data[$i]);
            }
        }
        return $this;
    }
}