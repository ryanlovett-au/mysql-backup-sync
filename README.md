## MySQL Backup/Sync

This application was written to provide a way to effect incremental backups of remote MySQL databases over sometimes unreliable internet connections. It was also written to provide a away to synchronise/move large databases, in active use, that may take several days to finish.

It is primarily designed to be used with databases that track row level changes through the use of an `updated_at` column, such as those used by the Laravel framework. However, other row level change tracking is also supported, such as write-once tables that only ever add new rows (and never edit rows). For tables that do not implement either of these approaches, full table sync on each run is also supported.

## Requirements

This is a TUI application written in the Laravel framework. It requires:	

 - Composer v2
 - PHP v8.2+ (with MySQL, SQLite, DOM/XML and CURL extensions - For Ubuntu 24.04 php8.3-xml php8.3-curl php-sqlite3 php-mysql)
 - A MySQL database in which to store backups

## Installation

To install:

 - Clone the repo
 - Change to the cloned directory
 - `composer install`
 - That's it!

 *You may also like to update the App Key in app.php (but not essential)*

## Configuration

Controversially, this application does not make use of a local .env file for configuration, preferring instead to use a local SQLite database. The migration and seeding will set this database up for you.

If you are super keen you can edit entries in this database directly, however I did go to great effort to write a TUI menu interface to provide a nicer way to configure the system.

Simply run `php artisan db:menu`.

### Configure Destination (local) Database 

The first step is to configure a destination (local/backup) database, this can be any MySQL database, however it should be directly accessible from the host running this application, preferably on the same machine or a low latency link.

```
 ┌ Configure Destination (Local) Host/Database ─────────────────┐
 │   ○ -------------------- Config ----------------------       │
 │   ○ BACKUP DB HOST     = 127.0.0.1                           │
 │   ○ BACKUP DB PORT     = 3306                                │
 │   ○ BACKUP DB USERNAME = root                                │
 │   ○ BACKUP DB PASSWORD =                                     │
 │   ○ SKIP TZ CHECK      = 1                                   │
 │   ○ --------------------------------------------------       │
 │ › ● Back                                                     │
 └──────────────────────────────────────────────────────────────┘
```

**Note:** The MySQL server you are using for backups really *must* have a matching timezone to the remote source servers. You can override this check here, however it is likely you will get timestamp/datetime errors during sync. You have been warned!

### Configure Source (Remote) Hosts

You can then proceed to configure any number of source (remote/original) hosts.

```
 ┌ Configure Source (Remote) Host/Database ─────────────────────┐
 │ › ● -------------------- Config ----------------------       │
 │   ○ DB HOST                    =                             │
 │   ○ DB PORT                    = 3306                        │
 │   ○ DB USERNAME                =                             │
 │   ○ DB PASSWORD                =                             │
 │   ○ USE SSH TUNNEL             =                             │
 │   ○ SSH HOST                   =                             │
 │   ○ SSH PORT                   =                             │
 │   ○ SSH USERNAME               =                             │
 │   ○ SSH PASSWORD               =                             │
 │   ○ SSH PUBLIC KEY PATH        =                             │
 │   ○ SSH PRIVATE KEY PATH       =                             │
 │   ○ --------------------------------------------------       │
 │   ○ Test Host Connection                                     │
 │   ○ Delete Host                                              │
 │   ○ Back                                                     │
 └──────────────────────────────────────────────────────────────┘
```

Source hosts can be directly connected servers, or this application can tunnel MySQL connections over an SSH tunnel. 

If using an SSH tunnel, the DB HOST must be the IP or hostname of the database server relative to the remote SSH endpoint. The DB PORT must be the port of the source MySQL server. You can either user a non-passphrase protected SSH key pair or a SSH password for this connection. All other ports are managed dynamically by this application.

You can then test your connection using the menu option provided.

### Configure Source Databases

Once you have configured a source host you can then proceed to add a database.

```
 │   ○ ------------------- Databases --------------------    │
 │   ○ Add Source Database                                   │
 │   ○ dispatch_dev                                          │
 │   ○ --------------------------------------------------    │
 ```

```
 ┌ Configure Database for 127.0.0.1 ───────────────────────────────┐
 │ › ● -------------------- Config ----------------------          │
 │   ○ DATABASE NAME   =                                           │
 │   ○ WEBHOOK SUCCESS =                                           │
 │   ○ WEBHOOK FAILURE =                                           │
 │   ○ IS ACTIVE       =                                           │
 │   ○ --------------------------------------------------          │
 │   ○ Delete Database                                             │
 │   ○ Back                                                        │
 └─────────────────────────────────────────────────────────────────┘
 ```

Once you have provided a database name, this application will then attempt to connect to that source database and populate a list of tables.

```
 ┌ Configure Database for 127.0.0.1 ───────────────────────────────┐
 │ › ● -------------------- Config ----------------------     ┃    │
 │   ○ DATABASE NAME   = dispatch_dev                         │    │
 │   ○ WEBHOOK SUCCESS =                                      │    │
 │   ○ WEBHOOK FAILURE =                                      │    │
 │   ○ IS ACTIVE       = 1                                    │    │
 │   ○ -------------------- Tables ----------------------     │    │
 │   ○ audit_log                                              │    │
 │   ○ avl_log                                                │    │
 │   ○ classifications                                        │    │
 │   ○ client_notes                                           │    │
 │   ○ clients                                                │    │
 │   ○ custom_fields                                          │    │
 │   ○ device_types                                           │    │
 │   ○ devices                                                │    │
 │   ○ dispatch_numbers                                       │    │
 └─────────────────────────────────────────────────────────────────┘
```

You can then choose, for each table, if you would like the application to skip that table during backup/sync, or what behaviour you would like the application to use when backing up/syncing.

## Running a Backup/Sync

You can then run a backup/sync from the main menu

```
 ┌ Main Menu ───────────────────────────────────────────────────┐
 │ › ● Run Backup/Sync                                          │
 │   ○ Configuration                                            │
 │   ○ Exit                                                     │
 └──────────────────────────────────────────────────────────────┘
 ```

Or by running `php artisan db:backup`.

The application will then iterate through every host and database and backup/sync each table based on the configured parameters. The application will display a real-time UI of what is happening.

*You may also use the `php artisan db:backup --host= --database=` arguments to target a specific host and/or database from the command line.* 

During this process the application will first compare the schemas of the source and destination backups and determine if tables have been added or removed from the source and will action those changes on the destination copy as required.

**Note:** Renaming a table will results in remove + add/resync.

The application will then compare the structure of each table to look for columns that have been added or removed, or changes to column types/lengths. In this version of the application it will not gracefully synchronise these changes.

**Note:** Making any changes to table/column structure will result in a remove + add/resync for the entire table.

The application will then proceed to copy rows between databases.

## How Statefulness/Incremental Backups Work

For each initial backup sync, this application will copy rows based on each table's primary key, in ascending order. Each 500 rows, the application will store the current primary key and if the sync is interrupted it will continue from the last known good primary key.

If the table has an `updated_at` column the application will also store the timestamp when it commences its first sync attempt.

For tables with an `updated_at` column, on subsequent runs, the application will:
 - Select, transfer and update all rows with an `updated_at` time >= the last run in ascending order of `updated_at`.
 - Upon every successful write of rows to the backup, will update the state with the `updated_at` timestamp of the last record written. Due to the sorted nature of these rows, this will keep a running state.
 - This approach will also capture any new rows in additional to updating changes rows.

For tables without an `updated_at` column, and where `always_resync` is set to false, the application will simply copy any rows with a primary key > the last run.

For tables where `always_resync` is set to true, the application will truncate and do a full resync on each run.

## Restoring a Backup

As this application is primarily designed to support Laravel applications, the recommended restore is to:

 - Install a fresh copy of your application or create a new database connection for it to use
 - Run the migrations for your application
 - Use an application like Navicat or Tables to copy data only from the backup to the new application

 Whilst you *can* use mysqldump or other similar applications to copy the structure and data from the backup, OR simply steer your application to use the backup as its new primary database, this is not a recommended approach.

## Troubleshooting

You may encounter errors during your use of this application, they are detailed here:
 - Error 1390: You are trying to insert/update too many rows (or too many rows with too many columns), reduce the chunk value on updates in the local config. 500 has proven to be a good balance of performance and stability.
 - Error 2006: The database server has run out of memory or otherwise gone away. This is generally due to a select size (number of rows) that are sorting unindexed columns in memory. Confirm indexes exist and/or reduce the nubmer of rows being selected in each chuck and/or do whole table resyncs (which do not sort) if the table is small enough.

## Contributing

PR's are always welcome.

## Warranties

I do use this application in production to backup and sync my production databases, however, your millage may vary. I do not warrant that it does anything, at all.

## License

The is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
