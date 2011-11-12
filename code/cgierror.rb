#!/usr/bin/env ruby
# $Id: cgierror.rb 146 2010-05-06 04:27:08Z tepperly $
#
# This file is part of the Azimuthal Map Creator.
# Copyright (C) 2010 Thomas G. W. Epperly NS6T
#
# The Azimuthal Map Creator is free software: you can redistribute it
# and/or modify it under the terms of the GNU Affero General Public
# License as published by the Free Software Foundation, either version
# 3 of the License, or (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
ENV['RUBY_GEMS'] = "/home8/brightly/ruby/gems"
require 'rubygems'
require 'sqlite3'

def logError(cgi, exc, file)
  SQLite3::Database.new("errors.db") { |db|
    db.execute("create table if not exists error ( id integer primary key asc autoincrement, time integer, desc text, backtrace text, filename text)")
    db.execute("create table if not exists params ( id integer, param text, value text)")
    db.execute("insert into error (time, desc, backtrace, filename) values (?, ?, ?, ?)",
               Time.now.to_i, exc.to_s, exc.backtrace.join("\n"), file)
    id = db.get_first_value("select last_insert_rowid()")
    if cgi
      cgi.keys.each { |key|
        db.execute("insert into params (id, param, value) values (?, ?, ?)",
                   id.to_i, key.to_s, cgi[key].to_s)
      }
    end
  }
end
