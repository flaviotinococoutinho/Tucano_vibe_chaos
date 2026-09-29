<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Http;

use Commerce\Ordering\Application\Page;
use Illuminate\Foundation\Http\FormRequest;

/** ?page=1&perPage=10: both optional, page from 1 and perPage from 1 to 50. */
final class ListOrdersRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'between:1,' . Page::MAX_SIZE],
        ];
    }

    public function page(): Page
    {
        return Page::of((int) $this->validated('page', 1), (int) $this->validated('perPage', Page::DEFAULT_SIZE));
    }
}
