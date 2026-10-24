"""Parse selected INSERT rows as data; never execute legacy SQL."""
import os
import re

def sql_string(value):
    if value == "NULL":
        return None
    if len(value) >= 2 and value[0] == "'" and value[-1] == "'":
        value = value[1:-1]
        replacements = {
            "0": "\0",
            "b": "\b",
            "n": "\n",
            "r": "\r",
            "t": "\t",
            "Z": "\x1a",
            "\\": "\\",
            "'": "'",
            '"': '"',
        }
        result = []
        index = 0
        while index < len(value):
            if value[index] == "\\" and index + 1 < len(value):
                index += 1
                result.append(replacements.get(value[index], value[index]))
            elif value[index:index + 2] == "''":
                result.append("'")
                index += 1
            else:
                result.append(value[index])
            index += 1
        return "".join(result)
    return value


def split_sql_values(raw):
    values = []
    start = 0
    quoted = False
    escaped = False
    index = 0
    while index < len(raw):
        char = raw[index]
        if quoted:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == "'":
                if index + 1 < len(raw) and raw[index + 1] == "'":
                    index += 1
                else:
                    quoted = False
        elif char == "'":
            quoted = True
        elif char == ",":
            values.append(raw[start:index].strip())
            start = index + 1
        index += 1
    values.append(raw[start:].strip())
    return values


def insert_rows(sql, table):
    if table.startswith('legacy_'):
        prefix=os.environ.get('RENDERGALLERY_LEGACY_TABLE_PREFIX','legacy_')
        if not re.fullmatch(r'[A-Za-z0-9_]+',prefix):
            raise ValueError('Invalid legacy table prefix')
        table=prefix+table[len('legacy_'):]
    pattern = re.compile(
        rf"INSERT INTO\s+`?{re.escape(table)}`?\s*\((.*?)\)\s*VALUES\s*",
        re.IGNORECASE | re.DOTALL,
    )
    for match in pattern.finditer(sql):
        columns = [column.strip().strip("`") for column in match.group(1).split(",")]
        index = match.end()
        while index < len(sql):
            while index < len(sql) and sql[index].isspace():
                index += 1
            if index >= len(sql) or sql[index] != "(":
                break
            start = index + 1
            index += 1
            quoted = False
            escaped = False
            while index < len(sql):
                char = sql[index]
                if quoted:
                    if escaped:
                        escaped = False
                    elif char == "\\":
                        escaped = True
                    elif char == "'":
                        if index + 1 < len(sql) and sql[index + 1] == "'":
                            index += 1
                        else:
                            quoted = False
                elif char == "'":
                    quoted = True
                elif char == ")":
                    yield {
                        column: sql_string(value)
                        for column, value in zip(columns, split_sql_values(sql[start:index]))
                    }
                    index += 1
                    break
                index += 1
            while index < len(sql) and sql[index].isspace():
                index += 1
            if index < len(sql) and sql[index] == ",":
                index += 1
                continue
            break


def safe_number(value, default=0):
    try:
        return int(value or default)
    except (TypeError, ValueError):
        return default


