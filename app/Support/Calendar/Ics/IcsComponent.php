<?php

namespace App\Support\Calendar\Ics;

/**
 * A component of an iCalendar object (VCALENDAR, VEVENT, VTIMEZONE, VALARM, …) with its
 * properties in file order and its sub-components. Serialises back to text with toString(), so
 * a CalDAV object can be read, changed and written without losing what we don't understand.
 */
final class IcsComponent
{
    /** @var list<IcsProperty> */
    public array $properties = [];

    /** @var list<IcsComponent> */
    public array $children = [];

    public function __construct(public readonly string $name) {}

    public function first(string $name): ?IcsProperty
    {
        $name = strtoupper($name);
        foreach ($this->properties as $property) {
            if ($property->name === $name) {
                return $property;
            }
        }

        return null;
    }

    /** @return list<IcsProperty> */
    public function all(string $name): array
    {
        $name = strtoupper($name);

        return array_values(array_filter($this->properties, fn (IcsProperty $p) => $p->name === $name));
    }

    /** The unescaped text of the first property $name, or null (also for an empty value). */
    public function text(string $name): ?string
    {
        $value = $this->first($name)?->textValue();

        return $value === null || trim($value) === '' ? null : $value;
    }

    public function add(IcsProperty $property): self
    {
        $this->properties[] = $property;

        return $this;
    }

    /** Replace every $property->name property with $property (in place of the first one). */
    public function set(IcsProperty $property): self
    {
        $replaced = false;
        $out = [];
        foreach ($this->properties as $existing) {
            if ($existing->name !== $property->name) {
                $out[] = $existing;
            } elseif (! $replaced) {
                $out[] = $property;
                $replaced = true;
            }
        }
        if (! $replaced) {
            $out[] = $property;
        }
        $this->properties = $out;

        return $this;
    }

    public function remove(string ...$names): self
    {
        $names = array_map('strtoupper', $names);
        $this->properties = array_values(array_filter($this->properties, fn (IcsProperty $p) => ! in_array($p->name, $names, true)));

        return $this;
    }

    /** @return list<IcsComponent> */
    public function children(string $name): array
    {
        $name = strtoupper($name);

        return array_values(array_filter($this->children, fn (IcsComponent $c) => $c->name === $name));
    }

    public function toString(): string
    {
        $out = "BEGIN:{$this->name}\r\n";
        foreach ($this->properties as $property) {
            $out .= $property->toLine();
        }
        foreach ($this->children as $child) {
            $out .= $child->toString();
        }

        return $out."END:{$this->name}\r\n";
    }
}
