# DB management.

Sqlite database management.

This folder contains simple db scripts and and migrations for db schema management for Lute.  The schema is managed following the ideas outlined at https://github.com/jzohrab/DbMigrator/blob/master/docs/managing_database_changes.md:

* Baseline schema and reference data are in `baseline`.
* All one-time migrations are stored in the `migrations` folder, and are applied once only, in filename-sorted order.
* All repeatable migrations are stored in the `migrations_repeatable` folder, and are applied every single migration run.

## Creating new migration scripts

```
# one-time schema changes:
$ composer db:newscript <some_name_here>

# things that should always be applied (create triggers, etc):
$ composer db:newrepeat <some_name_here>
```

These migration scripts should be committed to the repo.
