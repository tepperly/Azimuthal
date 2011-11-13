#!/usr/bin/env ruby
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
#  along with this program.  If not, see <http://www.gnu.org/licenses/>.
#
def pathType(genType, specType)
  if (genType == "Open")
    return 4
  else
    if specType.instance_of?(String)
      return { 'Unknown' => 0, 'Lake' => 1, 'Land' => 2, 'Island' => 3 }[specType]
    else
      return 0
    end
  end
end

def convertFile(src, dest)
  section = /^(Path|Open)\s+(\d+)\s+(Lake|Land|Island)?\s*("([^"]*)")?\s*$/
  coordinate = /^\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s*$/
  File.open(src, "r") { |input|
    File.open(dest, "wb") { |output|
      input.each_line { |line|
        if (md = section.match(line))
            output.write([0x7ffffff0 | pathType(md[1],md[3]), md[2].to_i].pack("NN"))
        elsif (md = coordinate.match(line))
          output.write([(md[1].to_f * 1000000.0).to_i, (md[2].to_f * 1000000.0).to_i].pack("NN"))
        else
          print "Unmatched: " + line
        end
      }
    }
  }
end

ARGV.each { |filename|
  newfile = filename.sub(/\.txt$/, ".bin")
  if File.file?(newfile)
    print "File " + newfile + " already exists.\n"
  else
    convertFile(filename, newfile)
  end
}
