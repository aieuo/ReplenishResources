<?php

namespace aieuo\formAPI;

use aieuo\formAPI\element\Button;
use pocketmine\Player;

class ListForm extends Form {

    protected $type = self::LIST_FORM;

    /** @var string */
    private $content = "";
    /** @var Button[] */
    private $buttons = [];

    /**
     * @param string $content
     * @return self
     */
    public function setContent(string $content): self {
        $this->content = $content;
        return $this;
    }

    /**
	 * @param string $content
	 * @param bool $newLine
	 * @return self
	 */
	public function appendContent(string $content, bool $newLine = true): self {
		$this->content .= ($newLine ? "\n" : "").$content;
		return $this;
	}

    /**
     * @return string
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * @param Button $button
     * @return self
     */
    public function addButton(Button $button): self {
        $this->buttons[] = $button;
        return $this;
    }

    /**
     * @param Button[] $buttons
     * @return self
     */
    public function addButtons(array $buttons): self {
        $this->buttons = array_merge($this->buttons, $buttons);
        return $this;
    }

    /**
     * @param Button[] $buttons
     * @return self
     */
    public function setButtons(array $buttons): self {
        $this->buttons = $buttons;
        return $this;
    }

    public function forEach(array $inputs, callable $func): self {
        foreach ($inputs as $input) {
            $func($this, $input);
        }
        return $this;
    }

    /**
     * @return Button[]
     */
    public function getButtons(): array {
        return $this->buttons;
    }

    public function getButton(int $index): ?Button {
        return $this->buttons[$index] ?? null;
    }

    public function getButtonById(string $id): ?Button {
        foreach ($this->getButtons() as $button) {
            if ($button->getUUId() === $id) return $button;
        }
        return null;
    }

    public function jsonSerialize(): array {
        $form = [
            "type" => "form",
            "title" => $this->title,
            "content" => str_replace("\\n", "\n", $this->content),
            "buttons" => $this->buttons
        ];
        $form = $this->reflectErrors($form);
        return $form;
    }

    public function reflectErrors(array $form): array {
        if (!empty($this->messages)) {
            $form["content"] = implode("\n", array_keys($this->messages))."\n".$form["content"];
        }
        return $form;
    }

    public function handleResponse(Player $player, $data): void {
        $this->lastResponse = [$player, $data];
        if ($data === null) {
            parent::handleResponse($player, $data);
            return;
        }

        $button = $this->getButton($data);
        if ($button === null or $button->getOnClick() === null) {
            parent::handleResponse($player, $data);
            return;
        }

        call_user_func($button->getOnClick(), $player);
    }
}