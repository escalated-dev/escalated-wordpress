<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Knowledge base article category.
 *
 * Mirrors the Laravel reference ArticleCategory model: a self-referencing
 * tree (parent_id), ordered by position then name, with a slug used for
 * public lookups. Storage is the escalated_article_categories table.
 */
class ArticleCategory
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('article_categories');
    }

    /**
     * Find a category by ID.
     *
     * @param  int  $id
     * @return object|null
     */
    public static function find($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );
    }

    /**
     * Find a category by slug.
     *
     * @param  string  $slug
     * @return object|null
     */
    public static function find_by_slug($slug)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug)
        );
    }

    /**
     * Create a new category.
     *
     * @return int|false Inserted ID or false on failure.
     */
    public static function create(array $data)
    {
        global $wpdb;
        $table = static::table();
        $now = current_time('mysql');

        $data['created_at'] = $now;
        $data['updated_at'] = $now;

        $result = $wpdb->insert($table, $data);

        return $result !== false ? $wpdb->insert_id : false;
    }

    /**
     * Update a category.
     *
     * @param  int  $id
     * @return bool
     */
    public static function update($id, array $data)
    {
        global $wpdb;
        $table = static::table();

        $data['updated_at'] = current_time('mysql');

        return $wpdb->update($table, $data, ['id' => $id]) !== false;
    }

    /**
     * Delete a category.
     *
     * @param  int  $id
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->delete($table, ['id' => $id]) !== false;
    }

    /**
     * All categories ordered by position then name.
     *
     * @return array
     */
    public static function all()
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY position ASC, name ASC"
        ) ?: [];
    }

    /**
     * All categories with a count of their (non-scoped) articles. Used by the
     * admin category index.
     *
     * @return array
     */
    public static function all_with_article_counts()
    {
        global $wpdb;
        $table = static::table();
        $articles = Article::table();

        $sql = "SELECT c.*, (
                    SELECT COUNT(*) FROM {$articles} a WHERE a.category_id = c.id
                ) AS articles_count
                FROM {$table} c
                ORDER BY c.position ASC, c.name ASC";

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Root categories (no parent) with a count of their PUBLISHED articles.
     * Used by the public knowledge base index.
     *
     * @return array
     */
    public static function roots_with_published_counts()
    {
        global $wpdb;
        $table = static::table();
        $articles = Article::table();

        $sql = "SELECT c.*, (
                    SELECT COUNT(*) FROM {$articles} a
                    WHERE a.category_id = c.id AND a.status = 'published'
                ) AS articles_count
                FROM {$table} c
                WHERE c.parent_id IS NULL
                ORDER BY c.position ASC, c.name ASC";

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Produce a unique slug derived from $base, appending -2, -3, ... on
     * collision. Optionally excludes a row (for updates).
     *
     * @param  string  $base  A pre-sanitised slug candidate.
     * @param  int|null  $exclude_id
     * @return string
     */
    public static function unique_slug($base, $exclude_id = null)
    {
        global $wpdb;
        $table = static::table();

        $base = $base !== '' ? $base : 'category';
        $slug = $base;
        $suffix = 2;

        while (true) {
            if ($exclude_id) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE slug = %s AND id != %d",
                    $slug,
                    (int) $exclude_id
                ));
            } else {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE slug = %s",
                    $slug
                ));
            }

            if (! $exists) {
                return $slug;
            }

            $slug = $base.'-'.$suffix;
            $suffix++;
        }
    }
}
