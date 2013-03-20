# -*- coding: iso-8859-1 -*-
#
# pyinotify.py - python interface to inotify
# Copyright (C) 2005-2007 Sébastien Martini <sebastien.martini@gmail.com>
#
# This program is free software; you can redistribute it and/or
# modify it under the terms of the GNU General Public License
# version 2 as published by the Free Software Foundation; version 2.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA
# 02111-1307, USA.

"""
pyinotify

@author: Sebastien Martini
@license: GPL 2
@contact: sebastien.martini@gmail.com
"""
# Current TODO
#
# - be sure that the libc's loading is generic enough
# - include/exclude mechanism
# - hashing Watch  ?
# - check exceptions
# - accept a proc_fun as argument ?
#

# Incompatibles Changes :
#
# drop support for python 2.3
# is_dir -> dir
# event_name -> maskname
# inotify.* -> pyinotify.
# -> pathname
# VERBOSE -> log.setLevel(level)
# print_err(msg) -> log.error(msg)
# _get_event_name(mask) -> get_masks(mask)
# EventsCodes.IN_* -> IN_*
#


# check version
import sys
if sys.version < '2.4':
    sys.stderr.write('This module requires at least Python 2.4\n')
    sys.exit(1)


# import directives
import threading
import os
import select
import struct
import fcntl
import errno
import termios
import array
import logging
from collections import deque
from datetime import datetime, timedelta
import fnmatch
import re
import ctypes
import ctypes.util


__author__ = "Sebastien Martini"

__version__ = "0.8.0d"

__metaclass__ = type  # Use new-style classes by default


# load libc
# ctypes.CDLL("libc.so.6")
LIBC = ctypes.cdll.LoadLibrary(ctypes.util.find_library('libc'))


# logging
log = logging.getLogger("pyinotify")
console_handler = logging.StreamHandler()
console_handler.setFormatter(logging.Formatter("%(levelname)s: %(message)s"))
log.addHandler(console_handler)
log.setLevel(20)


# speed-up execution with psyco
try:
    import psyco
    psyco.full()
except ImportError:
    log.info('You could try to speed-up the execution if you had psyco installed.')



### inotify's variables ###


class SysCtlINotify:
    """
    Access (read, write) inotify's variables through sysctl.
    
    """
    
    inotify_attrs = {'max_user_instances': 1,
                     'max_user_watches': 2,
                     'max_queued_events': 3}

    def __new__(cls, *p, **k):
        attrname = p[0]
        if not attrname in globals():
            globals()[attrname] = super(SysCtlINotify, cls).__new__(cls, *p, **k)
        return globals()[attrname]

    def __init__(self, attrname):
        sino = ctypes.c_int * 3
        self._attrname = attrname
        self._attr = sino(5, 20, SysCtlINotify.inotify_attrs[attrname])

    def get_val(self):
        """
        @return: stored value.
        @rtype: int
        """
        oldv = ctypes.c_int(0)
        size = ctypes.c_int(ctypes.sizeof(oldv))
        LIBC.sysctl(self._attr, 3,
                    ctypes.c_voidp(ctypes.addressof(oldv)), ctypes.addressof(size),
                    None, 0)
        return oldv.value

    def set_val(self, nval):
        """
        @param nval: set to nval.
        @type nval: int
        """
        oldv = ctypes.c_int(0)
        sizeo = ctypes.c_int(ctypes.sizeof(oldv))
        newv = ctypes.c_int(nval)
        sizen = ctypes.c_int(ctypes.sizeof(newv))
        LIBC.sysctl(self._attr, 3,
                    ctypes.c_voidp(ctypes.addressof(oldv)), ctypes.addressof(sizeo),
                    ctypes.c_voidp(ctypes.addressof(newv)), ctypes.addressof(sizen))

    value = property(get_val, set_val)

    def __repr__(self):
        return '<%s=%d>' % (self._attrname, self.get_val())


# singleton instances
#
# read int: myvar = max_queued_events.value
# update: max_queued_events.value = 42
#
for i in ('max_queued_events', 'max_user_instances', 'max_user_watches'):
    SysCtlINotify(i)


# fixme: put these tests elsewhere
#
# print max_queued_events
# print max_queued_events.value
# save = max_queued_events.value
# print save
# max_queued_events.value += 42
# print max_queued_events
# max_queued_events.value = save
# print max_queued_events


### iglob ###


# Code taken from standart Python Lib, slightly modified in order to work
# with pyinotify (don't exclude dotted files/dirs like .foo).
# Original version:
# http://svn.python.org/projects/python/trunk/Lib/glob.py

def iglob(pathname):
    if not has_magic(pathname):
        if hasattr(os.path, 'lexists'):
            if os.path.lexists(pathname):
                yield pathname
        else:
            if os.path.islink(pathname) or os.path.exists(pathname):
                yield pathname
        return
    dirname, basename = os.path.split(pathname)
    # relative pathname
    if not dirname:
        return
    # absolute pathname
    if has_magic(dirname):
        dirs = iglob(dirname)
    else:
        dirs = [dirname]
    if has_magic(basename):
        glob_in_dir = glob1
    else:
        glob_in_dir = glob0
    for dirname in dirs:
        for name in glob_in_dir(dirname, basename):
            yield os.path.join(dirname, name)

def glob1(dirname, pattern):
    if not dirname:
        dirname = os.curdir
    try:
        names = os.listdir(dirname)
    except os.error:
        return []
    return fnmatch.filter(names, pattern)

def glob0(dirname, basename):
    if basename == '' and os.path.isdir(dirname):
        # `os.path.split()` returns an empty basename for paths ending with a
        # directory separator.  'q*x/' should match only directories.
        return [basename]
    if hasattr(os.path, 'lexists'):
        if os.path.lexists(os.path.join(dirname, basename)):
            return [basename]
    else:
        if os.path.islink(os.path.join(dirname, basename)) or os.path.exists(os.path.join(dirname, basename)):
            return [basename]
    return []

magic_check = re.compile('[*?[]')

def has_magic(s):
    return magic_check.search(s) is not None



### Core ###


class EventsCodes:
    """
    Set of codes corresponding to each kind of events.
    Some of these flags are used to communicate with inotify, whereas
    the others are sent to userspace by inotify notifying some events.

    @cvar IN_ACCESS: File was accessed.
    @type IN_ACCESS: int
    @cvar IN_MODIFY: File was modified.
    @type IN_MODIFY: int
    @cvar IN_ATTRIB: Metadata changed.
    @type IN_ATTRIB: int
    @cvar IN_CLOSE_WRITE: Writtable file was closed.
    @type IN_CLOSE_WRITE: int
    @cvar IN_CLOSE_NOWRITE: Unwrittable file closed.
    @type IN_CLOSE_NOWRITE: int
    @cvar IN_OPEN: File was opened.
    @type IN_OPEN: int
    @cvar IN_MOVED_FROM: File was moved from X.
    @type IN_MOVED_FROM: int
    @cvar IN_MOVED_TO: File was moved to Y.
    @type IN_MOVED_TO: int
    @cvar IN_CREATE: Subfile was created.
    @type IN_CREATE: int
    @cvar IN_DELETE: Subfile was deleted.
    @type IN_DELETE: int
    @cvar IN_DELETE_SELF: Self (watched item itself) was deleted.
    @type IN_DELETE_SELF: int
    @cvar IN_MOVE_SELF: Self (watched item itself) was moved.
    @type IN_MOVE_SELF: int
    @cvar IN_UNMOUNT: Backing fs was unmounted.
    @type IN_UNMOUNT: int
    @cvar IN_Q_OVERFLOW: Event queued overflowed.
    @type IN_Q_OVERFLOW: int
    @cvar IN_IGNORED: File was ignored.
    @type IN_IGNORED: int
    @cvar IN_ONLYDIR: only watch the path if it is a directory (new
                      in kernel 2.6.15).
    @type IN_ONLYDIR: int
    @cvar IN_DONT_FOLLOW: don't follow a symlink (new in kernel 2.6.15).
                          IN_ONLYDIR we can make sure that we don't watch
                          the target of symlinks.
    @type IN_DONT_FOLLOW: int
    @cvar IN_MASK_ADD: add to the mask of an already existing watch (new
                       in kernel 2.6.14).
    @type IN_MASK_ADD: int
    @cvar IN_ISDIR: Event occurred against dir.
    @type IN_ISDIR: int
    @cvar IN_ONESHOT: Only send event once.
    @type IN_ONESHOT: int
    @cvar ALL_EVENTS: Alias for considering all of the events.
    @type ALL_EVENTS: int
    """

    # The idea here is 'configuration-as-code' - this way, we get our nice class
    # constants, but we also get nice human-friendly text mappings to do lookups
    # against as well, for free:
    FLAG_COLLECTIONS = {'OP_FLAGS': {
        'IN_ACCESS'        : 0x00000001,  # File was accessed
        'IN_MODIFY'        : 0x00000002,  # File was modified
        'IN_ATTRIB'        : 0x00000004,  # Metadata changed
        'IN_CLOSE_WRITE'   : 0x00000008,  # Writable file was closed
        'IN_CLOSE_NOWRITE' : 0x00000010,  # Unwritable file closed
        'IN_OPEN'          : 0x00000020,  # File was opened
        'IN_MOVED_FROM'    : 0x00000040,  # File was moved from X
        'IN_MOVED_TO'      : 0x00000080,  # File was moved to Y
        'IN_CREATE'        : 0x00000100,  # Subfile was created
        'IN_DELETE'        : 0x00000200,  # Subfile was deleted
        'IN_DELETE_SELF'   : 0x00000400,  # Self (watched item itself) was deleted
        'IN_MOVE_SELF'     : 0x00000800,  # Self (watched item itself) was moved
        },
                        'EVENT_FLAGS': {
        'IN_UNMOUNT'       : 0x00002000,  # Backing fs was unmounted
        'IN_Q_OVERFLOW'    : 0x00004000,  # Event queued overflowed
        'IN_IGNORED'       : 0x00008000,  # File was ignored
        },
                        'SPECIAL_FLAGS': {
        'IN_ONLYDIR'       : 0x01000000,  # only watch the path if it is a directory
        'IN_DONT_FOLLOW'   : 0x02000000,  # don't follow a symlink
        'IN_MASK_ADD'      : 0x20000000,  # add to the mask of an already existing watch
        'IN_ISDIR'         : 0x40000000,  # event occurred against dir
        'IN_ONESHOT'       : 0x80000000,  # only send event once
        },
                        }
    
    def get_masks(mask):
        """
        Return the event name associated to the mask parameter, never
        return IN_ISDIR. Only one event is returned, because only one
        event is raised once at a time.

        @param mask: mask.
        @type mask: int
        @return: event name.
        @rtype: str or None
        """
        return reduce(lambda x, y: x + '|' + y,
                      [ec for ec, val in EventsCodes.ALL_FLAGS.iteritems() \
                       if val & mask])
    
    get_masks = staticmethod(get_masks)


# So let's now turn the configuration into code
EventsCodes.ALL_FLAGS = {}
for flag, fval in EventsCodes.FLAG_COLLECTIONS.iteritems():
    # Make the collections' members directly accessible through the
    # class dictionary
    setattr(EventsCodes, flag, fval)
    
    # Collect all the flags under a common umbrella
    EventsCodes.ALL_FLAGS.update(fval)
    
    # Make the individual masks accessible as 'constants' at globals() scope
    for mask, mval in fval.iteritems():
        globals()[mask] = mval

# all 'normal' events
ALL_EVENTS = reduce(lambda x, y: x | y, EventsCodes.OP_FLAGS.itervalues())


class _Event:
    """
    Event structure, represent events raised by the system. This
    is the base class and should be subclassed.

    """
    def __init__(self, dict_):
        """
        Attach attributes (contained in dict_) to self.
        """
        for tpl in dict_.iteritems():
            setattr(self, *tpl)

    def __repr__(self):
        """
        @return: String representation.
        @rtype: str
        """  
        s = ''
        for attr, value in sorted(self.__dict__.items(),
                                  lambda x,y: cmp(x[0], y[0])):
            if attr.startswith('_'):
                continue
            if attr == 'mask':
                value = hex(getattr(self, attr))
            elif isinstance(value, str) and not value:
                value ="''"
            if attr == 'pathname' and value and self.dir:
                value += os.sep
            s += ' %s%s%s' % (color_theme.field_name(attr),
                              color_theme.punct('='),
                              color_theme.field_value(value))  

        s = '%s%s%s %s' % (color_theme.punct('<'),
                           color_theme.class_name(self.__class__.__name__),
                           s,
                           color_theme.punct('>'))
        return s


class _RawEvent(_Event):
    """
    Raw event, it contains only the informations provided by the system.
    It doesn't infer anything.
    """
    def __init__(self, wd, mask, cookie, name):
        """
        @param wd: Watch Descriptor.
        @type wd: int
        @param mask: Bitmask of events.
        @type mask: int
        @param cookie: Cookie.
        @type cookie: int
        @param name: Basename of the file or directory against which the
                     event was raised, in case where the watched directory
                     is the parent directory. None if the event was raised
                     on the watched item itself.
        @type name: string or None
        """
        # name: remove trailing '\0'
        super(_RawEvent, self).__init__({'wd': wd,
                                         'mask': mask,
                                         'cookie': cookie,
                                         'name': name.rstrip('\0')})
        log.debug(repr(self))


class Event(_Event):
    """
    This class contains all the useful informations about the observed
    event. However, the incorporation of each field is not guaranteed and
    depends on the type of event. In effect, some fields are irrelevant
    for some kind of event (for example 'cookie' is meaningless for
    IN_CREATE whereas it is useful for IN_MOVE_TO).

    The possible fields are:
      - wd (int): Watch Descriptor.
      - mask (int): Mask.
      - maskname (str): Readable event name.
      - path (str): path of the file or directory being watched.
      - name (str): Basename of the file or directory against which the
              event was raised, in case where the watched directory
              is the parent directory. None if the event was raised
              on the watched item itself. This field is always provided
              even if the string is ''.
      - pathname (str): path + name
      - cookie (int): Cookie.
      - dir (bool): is the event raised against directory.

    """
    def __init__(self, raw):      
        """
        Concretely, this is the raw event plus inferred infos.
        """
        _Event.__init__(self, raw)
        self.maskname = EventsCodes.get_masks(self.mask)
        try:
            if self.name:
                self.pathname = os.path.join(self.path, self.name)
            else:
                self.pathname = self.path
        except AttributeError:
            pass
        

class ProcessEventError(Exception):
    """
    ProcessEventError Exception. Raised on ProcessEvent error.
    """
    def __init__(self, msg):
        """
        @param msg: Exception string's description.
        @type msg: string
        """
        Exception.__init__(self, msg)


class _ProcessEvent:
    """
    Abstract processing event class.
    """    
    def __call__(self, event):
        """
        To behave like a functor the object must be callable.
        This method is a dispatch method. Lookup order:
          1. process_MASKNAME method
          2. process_FAMILY_NAME method
          3. otherwise call process_default

        @param event: Event to be processed.
        @type event: Event object
        @raise ProcessEventError: Event object undispatchable,
                                  unknown event.
        """
        mask = event.mask
        for ec in EventsCodes.ALL_FLAGS.iterkeys():
            if ec != 'IN_ISDIR' and (mask & EventsCodes.ALL_FLAGS[ec]):
                # 1- look for process_MASKNAME
                meth = getattr(self, 'process_%s' % ec, None)
                if meth is not None:
                    return meth(event)
                # 2- look for process_FAMILY_NAME
                meth = getattr(self, 'process_IN_%s' % ec.split('_')[1], None)
                if meth is not None:
                    return meth(event)
                # 3- default method process_default
                return self.process_default(event)
        else:
            raise ProcessEventError("Unknown mask 0x%08x" % mask)

    def __repr__(self):
        return '<%s>' % self.__class__.__name__


class _SysProcessEvent(_ProcessEvent):
    """
    There is three kind of processing according to each event:

      1. special handling (deletion from internal container, bug, ...).
      2. default treatment: which is applied to most of events.
      4. IN_ISDIR is never sent alone, he is piggybacked with a standart
         event, he is not processed as the others events, instead, its
         value is captured and appropriately aggregated to dst event.
    """
    def __init__(self, wm):
        """

        @param wm: Watch Manager.
        @type wm: WatchManager instance
        """
        self._watch_manager = wm  # watch manager
        self._mv_cookie = {}  # {cookie(int): (src_path(str), date), ...}
        self._mv = {}  # {src_path(str): (dst_path(str), date), ...}

    def cleanup(self):
        """
        Cleanup (delete) old (>1mn) records contained in self._mv_cookie
        and self._mv.
        """
        date_cur_ = datetime.now()
        for seq in [self._mv_cookie, self._mv]:
            for k in seq.keys():
               if (date_cur_ - seq[k][1]) > timedelta(minutes=1):
                   log.debug('cleanup: deleting entry %s' % seq[k][0])
                   del seq[k]

    def process_IN_CREATE(self, raw_event):
        """
        If the event concerns a directory and the auto_add flag of the
        targetted watch is set to True, a new watch is added on this
        new directory, with the same attributes's values than those of
        this watch.
        """
        # FIXME -- important problem
        # The rec=True is just a trick to try to handle mkdir -p /t1/t2/t3
        # Sadly, the underlying problem still remains unsolved. And in this
        # case the IN_CREATE events for t2 and t3 will never be caught.
        if raw_event.mask & IN_ISDIR:
            watch_ = self._watch_manager._wmd.get(raw_event.wd)
            if watch_.auto_add:
                self._watch_manager.add_watch(os.path.join(watch_.path,
                                                           raw_event.name),
                                              watch_.mask,
                                              proc_fun=watch_.proc_fun,
                                              rec=True,
                                              auto_add=watch_.auto_add)
        return self.process_default(raw_event)

    def process_IN_MOVED_FROM(self, raw_event):
        """
        Map the cookie with the source path (+ date for cleaning).
        """
        watch_ = self._watch_manager._wmd.get(raw_event.wd)
        path_ = watch_.path
        src_path = os.path.normpath(os.path.join(path_, raw_event.name))
        self._mv_cookie[raw_event.cookie] = (src_path, datetime.now())
        return self.process_default(raw_event, {'cookie': raw_event.cookie})

    def process_IN_MOVED_TO(self, raw_event):
        """
        Map the source path with the destination path (+ date for
        cleaning).
        """
        watch_ = self._watch_manager._wmd.get(raw_event.wd)
        path_ = watch_.path
        dst_path = os.path.normpath(os.path.join(path_, raw_event.name))
        mv_ = self._mv_cookie.get(raw_event.cookie)
        if mv_:
            self._mv[mv_[0]] = (dst_path, datetime.now())
        return self.process_default(raw_event, {'cookie': raw_event.cookie})

    def process_IN_MOVE_SELF(self, raw_event):
        """
        status: the following bug has been fixed in the recent kernels
                (fixme: which version ?). Now it raises IN_DELETE_SELF instead.
        
        Old kernels are bugged, this event is raised when the watched item
        was moved, so we must update its path, but under some circumstances it
        can be impossible: if its parent directory and its destination
        directory aren't watched. The kernel (see include/linux/fsnotify.h)
        doesn't bring us enough informations like the destination path of
        moved items.
        """
        watch_ = self._watch_manager._wmd.get(raw_event.wd)
        src_path = watch_.path
        mv_ = self._mv.get(src_path)
        if mv_:
            watch_.path = mv_[0]
        else:
            log.error('The path %s of this watch %s must not be trusted anymore' % \
                      (watch_.path, watch_))
            if not watch_.path.endswith('-wrong-path'):
                watch_.path += '-wrong-path'
        # FIXME: should we pass the cookie even if this is not standart?
        return self.process_default(raw_event)

    def process_IN_Q_OVERFLOW(self, raw_event):
        """
        Only signal overflow, most of the common flags are irrelevant
        for this event (path, wd, name).
        """
        return Event({'mask': raw_event.mask})

    def process_IN_IGNORED(self, raw_event):
        """
        The watch descriptor raised by this event is now ignored (forever),
        it can be safely deleted from watch manager dictionary.
        After this event we can be sure that neither the event queue
        neither the system will raise an event associated to this wd.
        """
        event_ = self.process_default(raw_event)
        try:
            del self._watch_manager._wmd[raw_event.wd]
        except KeyError, err:
            log.error(err)
        return event_

    def process_default(self, raw_event, to_append={}):
        """
        Common handling for the following events:

        IN_ACCESS, IN_MODIFY, IN_ATTRIB, IN_CLOSE_WRITE, IN_CLOSE_NOWRITE,
        IN_OPEN, IN_DELETE, IN_DELETE_SELF, IN_UNMOUNT.
        """
        ret = None
        watch_ = self._watch_manager._wmd.get(raw_event.wd)
        if raw_event.mask & (IN_DELETE_SELF | IN_MOVE_SELF):
            # unfornately information not provided by the kernel
            dir_ = watch_.dir
        else:
            dir_ = bool(raw_event.mask & IN_ISDIR)
	dict_ = {'wd': raw_event.wd,
                 'mask': raw_event.mask,
                 'path': watch_.path,
                 'name': raw_event.name,
                 'dir': dir_}
        dict_.update(to_append)
        return Event(dict_)


class ProcessEvent(_ProcessEvent):
    """
    Process events objects, can be specialized via subclassing,
    thus its behavior can be overriden:

    Note also: if you define an __init__ method you don't need to explicitely
    call its parent class.

      1. Define an individual method, e.g. process_IN_DELETE for processing
         one single kind of event (IN_DELETE in this case).
      2. Process events by 'family', e.g. the process_IN_CLOSE method will
         process both IN_CLOSE_WRITE and IN_CLOSE_NOWRITE events (if
         process_IN_CLOSE_WRITE and process_IN_CLOSE_NOWRITE aren't
         defined).
      3. Override process_default for processing the remaining kind of
         events.
    """
    pevent = None
    
    def __init__(self, pevent=None):
        """
        Permit chaining of ProcessEvent instances.
        """
        self.pevent = pevent

    def __call__(self, event):
        if self.pevent is not None:
            self.pevent(event)    
        _ProcessEvent.__call__(self, event)
        
    def process_default(self, event):
        """
        Default default processing event method. Print event
        on standart output.

        @param event: Event to be processed.
        @type event: Event instance
        """
        print repr(event)


class NotifierError(Exception):
    """
    Notifier Exception. Raised on Notifier error.

    """
    def __init__(self, msg):
        """
        @param msg: Exception string's description.
        @type msg: string
        """
        Exception.__init__(self, msg)


class Notifier:
    """
    Read notifications, process events.
    """
    def __init__(self, watch_manager, default_proc_fun=ProcessEvent()):
        """
        Initialization.

        @param watch_manager: Watch Manager.
        @type watch_manager: WatchManager instance
        @param default_proc_fun: Default processing method.
        @type default_proc_fun: instance of ProcessEvent
        """
        # watch manager instance
        self._watch_manager = watch_manager
        # file descriptor
        self._fd = self._watch_manager._fd
        # poll object and registration
        self._pollobj = select.poll()
        self._pollobj.register(self._fd, select.POLLIN)
        # event queue
        self._eventq = deque()
        # system processing functor, common to all events
        self._sys_proc_fun = _SysProcessEvent(self._watch_manager)
        # default processing method
        self._default_proc_fun = default_proc_fun

    def check_events(self, timeout=1000):
        """
        Check for new events available to read, blocks up to timeout
        milliseconds.

        @param timeout: The timeout is passed on to select.poll(), thus
                        timeout=0 means 'return immediately', timeout>0
                        means 'wait up to timeout milliseconds' and
                        timeout=None means 'wait eternally', i.e. block.
        @type timeout: int
        @return: New events to read.
        @rtype: bool
        """
        while True:
            try:
                # blocks up to 'timeout' milliseconds
                ret = self._pollobj.poll(timeout)
            except select.error, err:
                if err[0] == errno.EINTR:
                    continue # interrupted, retry
                else:
                    raise
            else:
                break

        if not ret:
            return False
        # only one fd is polled
        return ret[0][1] & select.POLLIN

    def read_events(self):
        """
        Read events from device, build _RawEvents, and enqueue them.
        """
        buf_ = array.array('i', [0])
        # get event queue size
        if fcntl.ioctl(self._fd, termios.FIONREAD, buf_, 1) == -1:
            return
        queue_size = buf_[0]
        try:
            # read content from file
            r = os.read(self._fd, queue_size)
        except Exception, msg:
            raise NotifierError(msg)
        log.debug('event queue size: %d' % queue_size)
        rsum = 0  # counter
        while rsum < queue_size:
            s_size = 16
            # retrieve wd, mask, cookie
            s_ = struct.unpack('iIII', r[rsum:rsum+s_size])
            # length of name
            fname_len = s_[3]
            # field 'length' useless
            s_ = s_[:-1]
            # retrieve name
            s_ += struct.unpack('%ds' % fname_len,
                                r[rsum + s_size:rsum + s_size + fname_len])
            self._eventq.append(_RawEvent(*s_))
            rsum += s_size + fname_len

    def process_events(self):
        """
        Routine for processing events from queue by calling their
        associated proccessing function (instance of ProcessEvent).
        It also do internal processings, to keep the system updated.
        """
        while self._eventq:
            raw_event = self._eventq.popleft()  # pop next event
            watch_ = self._watch_manager._wmd.get(raw_event.wd)
            revent = self._sys_proc_fun(raw_event)  # system processings
            if watch_ and watch_.proc_fun:
                watch_.proc_fun(revent)  # user processings
            else:
                self._default_proc_fun(revent)
        self._sys_proc_fun.cleanup()  # remove olds MOVED_* events records

    def stop(self):
        """
        Close the inotify's instance (close its file descriptor).
        It destroys all existing watches, pending events,...
        """
        self._pollobj.unregister(self._fd)
        os.close(self._fd)


class ThreadedNotifier(threading.Thread, Notifier):
    """
    This notifier inherits from threading.Thread instantiating a separate
    thread, and provide standart Notifier functionality. This is a threaded
    version of Notifier.
    """
    def __init__(self, watch_manager, default_proc_fun=ProcessEvent()):
        """
        Initialization, initialize base classes.

        @param watch_manager: Watch Manager.
        @type watch_manager: WatchManager instance
        @param default_proc_fun: Default processing method.
        @type default_proc_fun: instance of ProcessEvent
        """
        # init threading base class
        threading.Thread.__init__(self)
        # stop condition
        self._stop_event = threading.Event()
        # init Notifier base class
        Notifier.__init__(self, watch_manager, default_proc_fun)

    def stop(self):
        """
        Stop the notifier's thread. Stop notification.
        """
        self._stop_event.set()
        threading.Thread.join(self)
        Notifier.stop(self)

    def run(self):
        """
        Start the thread's task: Read and process events forever (i.e.
        until the method stop() is called).
        Don't call this method directly, instead call the start() method
        inherited from threading.Thread.
        """
        while not self._stop_event.isSet():
            self.process_events()
            if self.check_events():
                self.read_events()


class Watch:
    """
    Represent a watch, i.e. a file or directory being watched.

    """
    # FIXME: is the locking stuff necessary? most of ops seems to be
    #        atomic, locking seems to be superflu.
    def __init__(self, **keys):
        """
        Initializations.

        @param wd: Watch descriptor.
        @type wd: int
        @param path: Path of the file or directory being watched.
        @type path: str
        @param mask: Mask.
        @type mask: int
        @param proc_fun: Processing object.
        @type proc_fun:
        @param auto_add: Automatically add watches on new directories.
        @type auto_add: bool
        """
        object.__setattr__(self, '_w_lock', threading.RLock())
        for k,v in keys.iteritems():
            setattr(self, k, v)
        self.dir = os.path.isdir(self.path)

    def __getattribute__(self, name):
        """
        Override default behavior to add locking instructions.

        @param name: Attribute name.
        @type name: str
        @return: Attribute value.
        @rtype: undefined
        """
        lock_obj = object.__getattribute__(self, '_w_lock')
        lock_obj.acquire()
        try:
            ret = object.__getattribute__(self, name)
        finally:
            lock_obj.release()
        return ret

    def __setattr__(self, name, value):
        """
        Override default behavior to add locking instructions.

        @param name: Attribute name.
        @type name: str
        @param value: new value.
        @type value: undefined
        """
        self._w_lock.acquire()
        try:
            ret = object.__setattr__(self, name, value)
        finally:
            self._w_lock.release()
        return ret

    def __repr__(self):
        """
        @return: String representation.
        @rtype: str
        """
        s = ' '.join(['%s%s%s' % (color_theme.field_name(attr),
                                  color_theme.punct('='),
                                  color_theme.field_value(getattr(self, attr))) \
                      for attr in self.__dict__ if not attr.startswith('_')])

        s = '%s%s %s %s' % (color_theme.punct('<'),
                           color_theme.class_name(self.__class__.__name__),
                           s,
                           color_theme.punct('>'))
        return s


class WatchManager:
    """
    Provide operations for watching files and directories. Integrated
    dictionary is used to reference watched items.
    """
    def __init__(self):
        """
        Initialization: init inotify, init watch manager dictionary.
        Raise OSError if initialization fails.
        """
        self._wmd = {}  # watch dict key: watch descriptor, value: watch
        self._fd = LIBC.inotify_init() # inotify's init, file descriptor
        if self._fd < 0:
            raise OSError

    def __add_watch(self, path, mask, proc_fun, auto_add):
        """
        Add a watch on path, build a Watch object and insert it in the
        watch manager dictionary. Return the wd value.
        """
        wd_ = LIBC.inotify_add_watch(self._fd, path, mask)
        if wd_ < 0:
            log.error('add_watch: cannot watch %s (WD=%d)' % (path, wd_))
            return wd_
        watch_ = Watch(wd=wd_, path=os.path.normpath(path), mask=mask,
                       proc_fun=proc_fun, auto_add=auto_add)
        self._wmd[wd_] = watch_
        log.debug('New %s' % watch_)
        return wd_

    def __glob(self, path, do_glob):
        if do_glob:
            return iglob(path)
        else:
            return [path]

    def add_watch(self, path, mask, proc_fun=None, rec=False,
                  auto_add=False, do_glob=False):
        """
        Add watch(s) on given path(s) with the specified mask and
        optionnally with a processing function and recursive flag.

        @param path: Path to watch, the path can either be a file or a
                     directory. Also accepts a sequence (list) of paths.
        @type path: string or list of string
        @param mask: Bitmask of events.
        @type mask: int
        @param proc_fun: Processing object.
        @type proc_fun: function or ProcessEvent instance or instance of
                        one of its subclasses or callable object.
        @param rec: Recursively add watches from path on all its
                    subdirectories, set to False by default (doesn't
                    follows symlinks).
        @type rec: bool
        @param auto_add: Automatically add watches on newly created
                         directories in the watch's path.
        @type auto_add: bool
        @param do_glob: Do globbing on pathname.
        @type do_glob: bool
        @return: dict of paths associated to watch descriptors. A wd value
                 is positive if the watch has been sucessfully added,
                 otherwise the value is negative. If the path is invalid
                 it will be not included in this dict.
        @rtype: dict of str: int
        """
        ret_ = {} # return {path: wd, ...}

        # normalize args as list elements
        for npath in self.__format_param(path):
            # unix pathname pattern expansion
            for apath in self.__glob(npath, do_glob):
                # recursively list subdirs according to rec param
                for rpath in self.__walk_rec(apath, rec):
                    ret_[rpath] = self.__add_watch(rpath, mask,
                                                   proc_fun, auto_add)
	return ret_

    def __get_sub_rec(self, lpath):
        """
        Get every wd from self._wmd if its path is under the path of
        one (at least) of those in lpath. Doesn't follow symlinks.

        @param lpath: list of watch descriptor
        @type lpath: list of int
        @return: list of watch descriptor
        @rtype: list of int
        """
        for d in lpath:
            root = self.get_path(d)
            if root:
                # always keep root
                yield d
            else:
                # if invalid
                continue

            # nothing else to expect
            if not os.path.isdir(root):
                continue
            
            # normalization
            root = os.path.normpath(root)
            # recursion
            lend = len(root)
            for iwd in self._wmd.items():
                cur = iwd[1].path
                pref = os.path.commonprefix([root, cur])
                if root == os.sep or (len(pref) == lend and \
                                      len(cur) > lend and \
                                      cur[lend] == os.sep):
                    yield iwd[1].wd

    def update_watch(self, wd, mask=None, proc_fun=None, rec=False,
                     auto_add=False):
        """
        Update existing watch(s). Both the mask and the processing
        object can be modified.

        @param wd: Watch Descriptor to update. Also accepts a list of
                     watch descriptors.
        @type wd: int or list of int
        @param mask: Optional new bitmask of events.
        @type mask: int
        @param proc_fun: Optional new processing function.
        @type proc_fun: function or ProcessEvent instance or instance of
                        one of its subclasses or callable object.
        @param rec: Recursively update watches on every already watched
                    subdirectories and subfiles.
        @type rec: bool
        @param auto_add: Automatically add watches on newly created
                         directories in the watch's path.
        @type auto_add: bool
        @return: dict of watch descriptors associated to booleans values.
                 True if the corresponding wd has been successfully
                 updated, False otherwise.
        @rtype: dict of int: bool
        """
        lwd = self.__format_param(wd)
        if rec:
            lwd = self.__get_sub_rec(lwd)

        ret_ = {}  # return {wd: bool, ...}
        for awd in lwd:
            apath = self.get_path(awd)
            ret_[awd] = False
            if not apath or awd < 0:
                log.error('update_watch: invalid WD=%d' % awd)
                continue

            if mask:
                wd_ = LIBC.inotify_add_watch(self._fd, apath, mask)
                if wd_ < 0:
                    log.error(('update_watch: cannot update WD=%d (%s)'%
                               (wd_, apath)))
                    continue
                # fixme: not too strict ?
                assert(awd == wd_)

            if proc_fun or auto_add:
                watch_ = self._wmd[awd]

            if proc_fun:
                watch_.proc_fun = proc_fun

            if auto_add:
                watch_.proc_fun = auto_add

            ret_[awd] = True
            log.debug('Updated watch - %s' % self._wmd[awd])
	return ret_

    def __format_param(self, param):
        """
        @param param: Parameter.
        @type param: string or int
        @return: wrap param.
        @rtype: list of type(param)
        """
        if isinstance(param, list):
            for p_ in param:
                yield p_
        else:
            yield param

    def get_wd(self, path):
        """
        Returns the watch descriptor associated to path. This method
        has an prohibitive cost, always prefer to keep the WD.

        @param path: path.
        @type path: str
        @return: WD or None.
        @rtype: int or None
        """
        path = os.path.normpath(path)
        for iwd in self._wmd.items():
            if iwd[1].path == path:
                return iwd[0]
        log.error('get_wd: unknown path %s' % path)

    def get_path(self, wd):
        """
        Returns the path associated to WD, if WD doesn't exist
        None is returned.

        @param wd: watch descriptor.
        @type wd: int
        @return: path or None.
        @rtype: string or None
        """
        watch_ = self._wmd.get(wd)
        if watch_:
            return watch_.path
        log.error('get_path: unknown WD %d' % wd)

    def __walk_rec(self, top, rec):
        """
        Yields each subdirectories of top, doesn't follow symlinks.
        If rec is false, only yield top.

        @param top: root directory.
        @type top: string
        @param rec: recursive flag.
        @type rec: bool
        @return: path of one subdirectory.
        @rtype: string
        """
        if not rec or os.path.islink(top) or not os.path.isdir(top):
            yield top
        else:
            for root, dirs, files in os.walk(top):
                yield root

    def rm_watch(self, wd, rec=False):
        """
        Removes watch(s).

        @param wd: Watch Descriptor of the file or directory to unwatch.
                   Also accepts a list of WDs.
        @type wd: int or list of int.
        @param rec: Recursively removes watches on every already watched
                    subdirectories and subfiles.
        @type rec: bool
        @return: dict of watch descriptors associated to booleans values.
                 True if the corresponding wd has been successfully
                 removed, False otherwise.
        @rtype: dict of int: bool
        """
        lwd = self.__format_param(wd)
        if rec:
            lwd = self.__get_sub_rec(lwd)

        ret_ = {}  # return {wd: bool, ...}
        for awd in lwd:
            ret_[awd] = False
            apath = self.get_path(awd)
            # remove watch
            wd_ = LIBC.inotify_rm_watch(self._fd, awd)
            if wd_ < 0:
                log.error('rm_watch: cannot remove WD=%d' % awd)
                continue

            ret_[awd] = True
            log.debug('watch WD=%d (%s) removed' % (awd, apath))
        return ret_


#
# The color mechanism is taken from Scapy:
# http://www.secdev.org/projects/scapy/
# Thanks to Philippe Biondi for his awesome tool and design.
#

class Color:
    normal = "\033[0m"
    black = "\033[30m"
    red = "\033[31m"
    green = "\033[32m"
    yellow = "\033[33m"
    blue = "\033[34m"
    purple = "\033[35m"
    cyan = "\033[36m"
    grey = "\033[37m"

    bold = "\033[1m"
    uline = "\033[4m"
    blink = "\033[5m"
    invert = "\033[7m"
        
class ColorTheme:
    def __repr__(self):
        return "<%s>" % self.__class__.__name__
    def __getattr__(self, attr):
        return lambda x:x
        
class NoTheme(ColorTheme):
    pass

class AnsiColorTheme(ColorTheme):
    def __getattr__(self, attr):
        if attr.startswith("__"):
            raise AttributeError(attr)
        s = "style_%s" % attr 
        if s in self.__class__.__dict__:
            before = getattr(self, s)
            after = self.style_normal
        else:
            before = after = ""

        def do_style(val, fmt=None, before=before, after=after):
            if fmt is None:
                if type(val) is not str:
                    val = str(val)
            else:
                val = fmt % val
            return before+val+after
        return do_style
        
        
    style_normal = ""
    style_prompt = "" # '>>>'
    style_punct = "" 
    style_id = ""
    style_not_printable = ""
    style_class_name = ""
    style_field_name = ""
    style_field_value = ""
    style_emph_field_name = ""
    style_emph_field_value = ""
    style_watchlist_name = ""
    style_watchlist_type = ""
    style_watchlist_value = ""
    style_fail = ""
    style_success = ""
    style_odd = ""
    style_even = ""
    style_opening = ""
    style_active = ""
    style_closed = ""
    style_left = ""
    style_right = ""

class BlackAndWhite(AnsiColorTheme):
    pass

class DefaultTheme(AnsiColorTheme):
    style_normal = Color.normal
    style_prompt = Color.blue+Color.bold
    style_punct = Color.normal
    style_id = Color.blue+Color.bold
    style_not_printable = Color.grey
    style_class_name = Color.red+Color.bold
    style_field_name = Color.blue
    style_field_value = Color.purple
    style_emph_field_name = Color.blue+Color.uline+Color.bold
    style_emph_field_value = Color.purple+Color.uline+Color.bold
    style_watchlist_type = Color.blue
    style_watchlist_value = Color.purple
    style_fail = Color.red+Color.bold
    style_success = Color.blue+Color.bold
    style_even = Color.black+Color.bold
    style_odd = Color.black
    style_opening = Color.yellow
    style_active = Color.black
    style_closed = Color.grey
    style_left = Color.blue+Color.invert
    style_right = Color.red+Color.invert

color_theme = DefaultTheme()



def command_line():
    #
    # - By default the watched path is '/tmp' for all events.
    # - The monitoring execution blocks and serve forever, type c^c
    #   to stop it.
    #
    from optparse import OptionParser

    usage = "usage: %prog [options] [path1] [path2] [pathn]"

    parser = OptionParser(usage=usage)
    parser.add_option("-v", "--verbose", action="store_true",
                      dest="verbose", help="Verbose mode")
    parser.add_option("-r", "--recursive", action="store_true",
                      dest="recursive",
                      help="Add watches recursively on paths")
    parser.add_option("-a", "--auto_add", action="store_true",
                      dest="auto_add",
                      help="Automatically add watches on new directories")
    parser.add_option("-e", "--events-list", metavar="EVENT[,...]",
                      dest="events_list",
                      help="A comma-separated list of events to watch for - see the man page for valid options (defaults to everything)")

    (options, args) = parser.parse_args()

    if options.verbose:
        log.setLevel(10)

    if len(args) < 1:
        path = '/tmp'  # default watched path
    else:
        path = args

    # watch manager instance
    wm = WatchManager()
    # notifier instance and init
    notifier = Notifier(wm)

    # What mask to apply
    mask = 0
    if options.events_list:
        events_list = options.events_list.split(',')
        for ev in events_list:
            evcode = EventsCodes.ALL_FLAGS.get(ev, 0)
            if evcode:
                mask |= evcode
            else:
                parser.error("The event '%s' specified with option -e is not valid" % ev)
    else:
        mask = ALL_EVENTS

    wm.add_watch(path, mask, rec=options.recursive, auto_add=options.auto_add)

    log.debug('start monitoring %s, (press c^c to halt pyinotify)' % path)
    # read and process events
    while True:
        try:
            notifier.process_events()
            if notifier.check_events():
                notifier.read_events()
        except KeyboardInterrupt:
            # ...until c^c signal
            log.debug('stop monitoring...')
            # stop monitoring
            notifier.stop()
            break


if __name__ == '__main__':
    command_line()
