<?php

namespace Escalated\Models;

use Escalated\Escalated;

/**
 * Knowledge base article.
 *
 * Mirrors the Laravel reference Article model: draft/published status with a
 * published_at timestamp, a unique slug for public lookups, an optional
 * category, an author, and view/helpful counters. Storage is the
 * escalated_articles table (fixing the previous unregistered-CPT stub).
 */
class Article
{
    /**
     * Get the table name.
     *
     * @return string
     */
    public static function table()
    {
        return Escalated::table('articles');
    }

    /**
     * Find an article by ID.
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
     * Find an article by slug (any status).
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
     * Find a PUBLISHED article by slug. Unpublished (draft) articles are never
     * returned to the public.
     *
     * @param  string  $slug
     * @return object|null
     */
    public static function find_published_by_slug($slug)
    {
        global $wpdb;
        $table = static::table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE slug = %s AND status = 'published'",
                $slug
            )
        );
    }

    /**
     * Create a new article.
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
     * Update an article.
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
     * Delete an article.
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
     * Admin listing with optional filters: search, status, category_id.
     *
     * @return array
     */
    public static function all(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ['1=1'];
        $values = [];

        if (! empty($filters['search'])) {
            $like = '%'.$wpdb->esc_like($filters['search']).'%';
            $where[] = '(title LIKE %s OR body LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        if (! empty($filters['status'])) {
            $where[] = 'status = %s';
            $values[] = $filters['status'];
        }

        if (! empty($filters['category_id'])) {
            $where[] = 'category_id = %d';
            $values[] = (int) $filters['category_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at DESC, id DESC";

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Public listing of PUBLISHED articles with optional search + category
     * filter, newest published first.
     *
     * @return array
     */
    public static function published(array $filters = [])
    {
        global $wpdb;
        $table = static::table();
        $where = ["status = 'published'"];
        $values = [];

        if (! empty($filters['search'])) {
            $like = '%'.$wpdb->esc_like($filters['search']).'%';
            $where[] = '(title LIKE %s OR body LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        if (! empty($filters['category_id'])) {
            $where[] = 'category_id = %d';
            $values[] = (int) $filters['category_id'];
        }

        $where_clause = implode(' AND ', $where);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY published_at DESC, id DESC";

        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 0;
        if ($limit > 0) {
            $sql .= ' LIMIT '.$limit;
        }

        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql) ?: [];
    }

    /**
     * Other published articles in the same category (excluding one), newest
     * first. Used to build the "related articles" list.
     *
     * @param  int|null  $category_id
     * @param  int  $exclude_id
     * @param  int  $limit
     * @return array
     */
    public static function related($category_id, $exclude_id, $limit = 5)
    {
        global $wpdb;
        $table = static::table();

        if (empty($category_id)) {
            return [];
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, slug FROM {$table}
             WHERE status = 'published' AND category_id = %d AND id != %d
             ORDER BY published_at DESC, id DESC
             LIMIT %d",
            (int) $category_id,
            (int) $exclude_id,
            (int) $limit
        )) ?: [];
    }

    /**
     * Increment the view counter for an article.
     *
     * @param  int  $id
     * @return bool
     */
    public static function increment_views($id)
    {
        return static::bump_counter($id, 'view_count');
    }

    /**
     * Increment the helpful counter for an article.
     *
     * @param  int  $id
     * @return bool
     */
    public static function mark_helpful($id)
    {
        return static::bump_counter($id, 'helpful_count');
    }

    /**
     * Increment the not-helpful counter for an article.
     *
     * @param  int  $id
     * @return bool
     */
    public static function mark_not_helpful($id)
    {
        return static::bump_counter($id, 'not_helpful_count');
    }

    /**
     * Atomically increment one of the integer counter columns.
     *
     * @param  int  $id
     * @param  string  $column  One of view_count|helpful_count|not_helpful_count.
     * @return bool
     */
    private static function bump_counter($id, $column)
    {
        global $wpdb;
        $table = static::table();

        $allowed = ['view_count', 'helpful_count', 'not_helpful_count'];
        if (! in_array($column, $allowed, true)) {
            return false;
        }

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
            (int) $id
        )) !== false;
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

        $base = $base !== '' ? $base : 'article';
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
