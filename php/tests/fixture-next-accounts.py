"""Fictional migration database. Never reads real source accounts."""
import json
from pathlib import Path
import sqlite3
import sys

file = Path(sys.argv[1])
values = json.load(sys.stdin)
with sqlite3.connect(file) as db:
    db.executescript("""
CREATE TABLE users(id INTEGER PRIMARY KEY,username TEXT,password TEXT,created TEXT);
CREATE TABLE legacy_accounts(legacy_id INTEGER PRIMARY KEY,username TEXT,password TEXT,enabled INTEGER,user_id INTEGER,is_admin INTEGER);
CREATE TABLE legacy_artwork(artwork_id INTEGER PRIMARY KEY,legacy_user_id INTEGER);
CREATE TABLE account_policy(account_key TEXT PRIMARY KEY,role TEXT,disabled INTEGER,auto_approve INTEGER);
CREATE TABLE account_activity(user_id INTEGER PRIMARY KEY,last_login INTEGER,last_seen INTEGER,login_count INTEGER);
CREATE TABLE legacy_account_dates(legacy_id INTEGER PRIMARY KEY,joined INTEGER,last_visit INTEGER);
CREATE TABLE artwork_metadata(artwork_id INTEGER PRIMARY KEY,title TEXT,description TEXT,category_id INTEGER,tags TEXT,hidden INTEGER);
CREATE TABLE legacy_comments(legacy_id INTEGER PRIMARY KEY,artwork_id INTEGER,legacy_user_id INTEGER,username TEXT,body TEXT,created TEXT,deleted INTEGER DEFAULT 0);
CREATE TABLE comments(id INTEGER PRIMARY KEY,artwork_id INTEGER,user_id INTEGER,body TEXT,created TEXT);
CREATE TABLE comment_moderation(comment_id INTEGER PRIMARY KEY,reason TEXT);
CREATE TABLE favorites(artwork_id INTEGER,user_id INTEGER);
CREATE TABLE artwork_software(artwork_id INTEGER PRIMARY KEY,software TEXT);
""")
    db.execute("INSERT INTO users VALUES(1,'next_admin',?,'2024-02-03 04:05:06')", (values['scrypt'],))
    db.execute("INSERT INTO favorites VALUES(7,1)")
    db.execute("INSERT INTO legacy_accounts VALUES(11,'OriginalAdmin',NULL,1,1,0)")
    db.execute("INSERT INTO legacy_accounts VALUES(12,'original_member',?,1,NULL,0)", (values['salted'],))
    db.execute("INSERT INTO legacy_accounts VALUES(13,'original_disabled',?,0,NULL,1)", (values['md5'],))
    db.execute("INSERT INTO legacy_accounts VALUES(14,'original_admin',?,1,NULL,1)", (values['md5'],))
    db.execute("INSERT INTO legacy_accounts VALUES(15,'original_owner',?,1,NULL,0)", (values['md5'],))
    db.execute("INSERT INTO legacy_accounts VALUES(16,'original_trusted',?,1,NULL,0)", (values['md5'],))
    db.execute("INSERT INTO legacy_accounts VALUES(17,'original_expired',?,1,NULL,0)", (values['md5'],))
    db.execute("INSERT INTO legacy_comments VALUES(9001,5,15,'Original owner','Fictional historical comment','2001-01-01 00:00:00',0)")
    db.execute("INSERT INTO legacy_comments VALUES(9002,1,0,'Former guest','Fictional held history','2001-01-02 00:00:00',0)")
    db.execute("INSERT INTO comment_moderation VALUES(-9002,'Held by old moderation')")
    db.execute("INSERT INTO account_policy VALUES('local:1','admin',0,1)")
    db.execute("INSERT INTO account_policy VALUES('legacy:11','member',1,0)")
    db.execute("INSERT INTO account_policy VALUES('legacy:12','member',0,1)")
    db.execute("INSERT INTO account_activity VALUES(1,1700000000000,1700000000000,7)")
    db.execute("INSERT INTO legacy_account_dates VALUES(12,946684800000,1000000000000)")
    db.executemany("INSERT INTO legacy_artwork VALUES(?,?)", [(1,11),(2,12),(3,13),(4,14),(5,15)])
    db.execute("INSERT INTO artwork_metadata VALUES(6,'Hidden imported item','Moderation must survive',1,'',1)")
