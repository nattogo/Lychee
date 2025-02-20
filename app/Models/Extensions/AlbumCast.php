<?php

namespace App\Models\Extensions;

use AccessControl;
use App\SmartAlbums\TagAlbum;
use Helpers;

trait AlbumCast
{
	use AlbumBooleans;
	use AlbumStringify;

	/**
	 * Returns album-attributes into a front-end friendly format. Note that some attributes remain unchanged.
	 *
	 * @return array
	 */
	public function toReturnArray(): array
	{
		$return = [
			'id' => strval($this->id),
			'title' => $this->title,
			'public' => Helpers::str_of_bool($this->is_public()),
			'full_photo' => Helpers::str_of_bool($this->is_full_photo_visible()),
			'visible' => strval($this->viewable),
			'nsfw' => strval($this->nsfw),
			'parent_id' => $this->str_parent_id(),
			'cover_id' => strval($this->cover_id),
			'description' => strval($this->description),

			'downloadable' => Helpers::str_of_bool($this->is_downloadable()),
			'share_button_visible' => Helpers::str_of_bool($this->is_share_button_visible()),

			'created_at' => $this->created_at->format(\DateTimeInterface::ISO8601),
			'updated_at' => $this->updated_at->format(\DateTimeInterface::ISO8601),
			'min_taken_at' => $this->min_taken_at !== null ? $this->min_taken_at->format(\DateTimeInterface::ISO8601) : null,
			'max_taken_at' => $this->max_taken_at !== null ? $this->max_taken_at->format(\DateTimeInterface::ISO8601) : null,

			// Parse password
			'password' => Helpers::str_of_bool($this->password != ''),
			'license' => $this->get_license(),

			// Parse Ordering
			'sorting_col' => $this->sorting_col,
			'sorting_order' => $this->sorting_order,

			'thumb' => optional($this->get_thumb())->toArray(),
			'has_albums' => Helpers::str_of_bool($this->isLeaf() === false),
		];

		if ($this->is_tag_album()) {
			$return['tag_album'] = '1';
			$return['show_tags'] = $this->showtags;
		}

		if (!empty($this->showtags) || !$this->smart) {
			if (AccessControl::is_logged_in()) {
				$return['owner'] = $this->owner->name();
			}
		}

		return $return;
	}

	public function toTagAlbum(): TagAlbum
	{
		/**
		 * ! DO NOT USE ->save() on this object!
		 * It is convenient to quickly convert, but if you want to ->save(),
		 * this will create conflict in the database as NestedTree thinks it
		 * is a new object and not an already existing one.
		 */
		$tag_album = resolve(TagAlbum::class);
		$tag_album->id = $this->id;
		$tag_album->title = $this->title;
		$tag_album->owner_id = $this->owner_id;
		$tag_album->parent_id = $this->parent_id;
		$tag_album->_lft = $this->_lft;
		$tag_album->_rgt = $this->_rgt;
		$tag_album->description = $this->description ?? '';
		$tag_album->min_taken_at = $this->min_taken_at;
		$tag_album->max_taken_at = $this->max_taken_at;
		$tag_album->public = $this->public;
		$tag_album->full_photo = $this->full_photo;
		$tag_album->viewable = $this->viewable;
		$tag_album->nsfw = $this->nsfw;
		$tag_album->downloadable = $this->downloadable;
		$tag_album->password = $this->password;
		$tag_album->license = $this->license;
		$tag_album->created_at = $this->created_at;
		$tag_album->updated_at = $this->updated_at;
		$tag_album->share_button_visible = $this->share_button_visible;
		$tag_album->smart = $this->smart;
		$tag_album->showtags = $this->showtags;

		return $tag_album;
	}
}
